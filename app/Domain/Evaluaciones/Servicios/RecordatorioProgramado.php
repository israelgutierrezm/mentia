<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Contratos\CanalNotificacion;
use App\Domain\Evaluaciones\Datos\Invitacion;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;

/**
 * Recordatorios que salen solos.
 *
 * TRES REGLAS, y las tres existen porque el recordatorio molesta:
 *
 * 1. **Cadencia mínima.** No se le escribe a la misma persona dos días
 *    seguidos. Un sistema que insiste todos los días se gana que lo marquen
 *    como spam, y entonces tampoco llega la invitación de la siguiente campaña.
 * 2. **Tope de recordatorios.** Después de N, se deja de insistir. Quien no
 *    contestó tras tres avisos no va a contestar por un cuarto; lo que hace
 *    falta ahí es que alguien lo llame.
 * 3. **Sólo con la ventana abierta.** Recordar algo que ya no se puede
 *    contestar sólo genera llamadas a soporte.
 *
 * El último día se hace una excepción a la cadencia: es la única insistencia
 * que sirve de verdad, porque después ya no hay nada que hacer.
 */
class RecordatorioProgramado
{
    /** Días mínimos entre recordatorios a la misma persona. */
    public const DIAS_ENTRE_RECORDATORIOS = 2;

    /** Cuántos recordatorios como máximo, sin contar la invitación. */
    public const TOPE = 3;

    public function __construct(
        private readonly CanalNotificacion $canal,
        private readonly GestorTokens $tokens,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * Recorre todas las asignaciones abiertas y manda lo que toque.
     *
     * @return int Cuántos recordatorios salieron.
     */
    public function correr(?Carbon $al = null): int
    {
        $momento = $al ?? Carbon::now();

        /*
         * Sin restricción de tenant: el job corre para toda la plataforma y
         * los global scopes fallan cerrado, así que sin esto no vería ninguna
         * asignación.
         */
        return $this->contexto->sinRestriccion(function () use ($momento): int {
            $asignaciones = Asignacion::query()
                ->where('estado', 'activa')
                ->where('ventana_inicio', '<=', $momento)
                ->where('ventana_fin', '>=', $momento)
                ->get();

            $enviados = 0;

            foreach ($asignaciones as $asignacion) {
                $enviados += $this->paraAsignacion($asignacion, $momento);
            }

            return $enviados;
        });
    }

    public function paraAsignacion(Asignacion $asignacion, ?Carbon $al = null): int
    {
        $momento = $al ?? Carbon::now();

        if (! $asignacion->ventanaAbierta($momento)) {
            return 0;
        }

        $destinatarios = AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->whereIn('estado', ['notificada', 'en_curso'])
            ->where('recordatorios_enviados', '<', self::TOPE)
            ->with(['persona.usuario', 'quienResponde.usuario'])
            ->get();

        $enviados = 0;

        foreach ($destinatarios as $destinatario) {
            if (! $this->toca($destinatario, $asignacion, $momento)) {
                continue;
            }

            $destinatario->setRelation('asignacion', $asignacion);

            $this->canal->enviar(Invitacion::para(
                $destinatario,
                $this->tokens->generar($destinatario),
                esRecordatorio: true
            ));

            $destinatario->update([
                'recordatorios_enviados' => $destinatario->recordatorios_enviados + 1,
                'notificada_en' => $momento,
            ]);

            $enviados++;
        }

        return $enviados;
    }

    /**
     * ¿Le toca a esta persona hoy?
     */
    private function toca(
        AsignacionDestinatario $destinatario,
        Asignacion $asignacion,
        Carbon $momento,
    ): bool {
        $ultimo = $destinatario->notificada_en;

        if ($ultimo === null) {
            // Nunca se le escribió: eso es una invitación, no un recordatorio.
            return false;
        }

        /*
         * Último día de la ventana: se salta la cadencia. Es la única
         * insistencia que sirve de verdad, porque después ya no hay nada que
         * hacer.
         */
        if ($asignacion->ventana_fin->isSameDay($momento)) {
            return ! $ultimo->isSameDay($momento);
        }

        return $ultimo->diffInDays($momento) >= self::DIAS_ENTRE_RECORDATORIOS;
    }
}
