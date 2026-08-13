<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Contratos\CanalNotificacion;
use App\Domain\Evaluaciones\Datos\Invitacion;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use Illuminate\Support\Carbon;

/**
 * Manda invitaciones y recordatorios.
 *
 * Un detalle que gobierna el diseño: el token en claro sólo existe cuando se
 * GENERA. Así que notificar implica REGENERAR el token, y eso invalida la liga
 * anterior. Es lo correcto —una liga vieja circulando es una liga que alguien
 * más puede usar— pero significa que un recordatorio deja inservible el correo
 * original, y por eso el texto lo advierte.
 */
class NotificadorAsignaciones
{
    public function __construct(
        private readonly CanalNotificacion $canal,
        private readonly GestorTokens $tokens,
    ) {}

    /**
     * Invita a todos los que no han contestado.
     *
     * @return int Cuántas invitaciones salieron.
     */
    public function invitar(Asignacion $asignacion): int
    {
        $destinatarios = AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->whereIn('estado', ['pendiente', 'consentimiento_pendiente'])
            ->with(['persona.usuario', 'quienResponde.usuario'])
            ->get();

        $enviadas = 0;

        foreach ($destinatarios as $destinatario) {
            $destinatario->setRelation('asignacion', $asignacion);

            $this->canal->enviar(Invitacion::para(
                $destinatario,
                $this->tokens->generar($destinatario)
            ));

            $destinatario->update([
                'estado' => 'notificada',
                'notificada_en' => Carbon::now(),
            ]);

            $enviadas++;
        }

        return $enviadas;
    }

    /**
     * Recordatorio a quien sigue sin contestar.
     *
     * Sólo a los que YA fueron notificados o están a medias: mandarle un
     * recordatorio a alguien que nunca recibió la invitación original es
     * confuso —y quien está en `consentimiento_pendiente` necesita otra cosa,
     * no un recordatorio—.
     */
    public function recordar(Asignacion $asignacion): int
    {
        if (! $asignacion->ventanaAbierta()) {
            // Recordar algo que ya no se puede contestar sólo genera llamadas
            // a soporte.
            return 0;
        }

        $destinatarios = AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->whereIn('estado', ['notificada', 'en_curso'])
            ->with(['persona.usuario', 'quienResponde.usuario'])
            ->get();

        $enviados = 0;

        foreach ($destinatarios as $destinatario) {
            $destinatario->setRelation('asignacion', $asignacion);

            $this->canal->enviar(Invitacion::para(
                $destinatario,
                $this->tokens->generar($destinatario),
                esRecordatorio: true
            ));

            $destinatario->increment('recordatorios_enviados');
            $enviados++;
        }

        return $enviados;
    }
}
