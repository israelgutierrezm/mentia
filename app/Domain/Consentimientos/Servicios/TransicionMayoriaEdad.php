<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Servicios;

use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Expedientes\Modelos\Expediente;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qué pasa el día que un menor cumple 18 años (Doc 06 §3, LFPDPPP).
 *
 * Tres efectos en la misma transacción:
 *
 * 1. Las tutorías vigentes pasan a `extinta_mayoria_edad`. Quien tenía tutela
 *    deja de tenerla: la persona ya es titular de sus propios datos.
 * 2. Los consentimientos que otorgó un TUTOR pasan a
 *    `pendiente_reconsentimiento`. Nadie consintió por sí mismo todavía, y lo
 *    que la madre autorizó cuando tenía 12 años no vincula a quien ya es mayor.
 * 3. El expediente queda BLOQUEADO para terceros hasta que la persona
 *    re-consienta. El titular siempre entra a lo suyo; los demás, no.
 *
 * El punto 3 es el que la ley obliga y el que duele operativamente: una escuela
 * pierde acceso al expediente de su alumno de 18 años hasta que él decida. Es
 * lo correcto — el dato es suyo, no de la escuela.
 */
class TransicionMayoriaEdad
{
    public const MOTIVO_BLOQUEO = 'Pendiente de re-consentimiento por mayoría de edad.';

    /**
     * Corre la transición para todas las personas que ya cumplieron 18 y
     * todavía tienen tutelas vigentes.
     *
     * @return int Cuántas personas transitaron.
     */
    public function correr(?Carbon $al = null): int
    {
        $fecha = $al ?? Carbon::now();
        $limite = $fecha->copy()->subYears(18)->toDateString();

        /*
         * Se buscan por TUTELA VIGENTE y no por edad a secas: repasar a todas
         * las personas mayores de 18 en cada corrida sería barrer la tabla
         * entera todos los días para no hacer nada.
         */
        $personas = Persona::query()
            ->whereDate('fecha_nacimiento', '<=', $limite)
            ->whereHas('tutores', fn ($consulta) => $consulta->where('estado', 'vigente'))
            ->get();

        foreach ($personas as $persona) {
            $this->transitar($persona, $fecha);
        }

        return $personas->count();
    }

    public function transitar(Persona $persona, ?Carbon $al = null): void
    {
        $fecha = $al ?? Carbon::now();

        DB::transaction(function () use ($persona, $fecha): void {
            Tutoria::query()
                ->where('menor_persona_id', $persona->id)
                ->where('estado', 'vigente')
                ->update([
                    'estado' => 'extinta_mayoria_edad',
                    'vigencia_fin' => $fecha->toDateString(),
                ]);

            $reconsentir = Consentimiento::query()
                ->where('persona_id', $persona->id)
                ->where('relacion', 'tutor')
                ->where('estado', 'vigente')
                ->update(['estado' => 'pendiente_reconsentimiento']);

            /*
             * Sólo se bloquea si HABÍA consentimientos de tutor que ahora
             * quedan en el aire. Una persona que siempre consintió por sí
             * misma no tiene por qué perder acceso el día de su cumpleaños.
             */
            if ($reconsentir > 0) {
                /*
                 * `firstOrCreate` y no `update`: el expediente nace la primera
                 * vez que alguien captura algo, así que una persona tamizada
                 * pero sin captura todavía NO tiene fila. Un `update` no
                 * afectaría a nadie y el bloqueo sería un no-op silencioso
                 * —justo en el caso que la ley obliga a bloquear—.
                 */
                Expediente::query()->firstOrCreate(
                    ['persona_id' => $persona->id],
                    ['estado' => 'activo']
                )->update([
                    'estado' => 'bloqueado',
                    'motivo_bloqueo' => self::MOTIVO_BLOQUEO,
                ]);
            }
        });
    }

    /**
     * Levanta el bloqueo cuando la persona ya consintió por sí misma.
     *
     * Se llama desde GestorConsentimientos al otorgar: el desbloqueo es
     * consecuencia del acto de consentir, no un botón aparte que alguien
     * tenga que acordarse de apretar.
     */
    public function levantarBloqueoSiProcede(Persona $persona): void
    {
        $sigueEnEspera = Consentimiento::query()
            ->where('persona_id', $persona->id)
            ->where('estado', 'pendiente_reconsentimiento')
            ->exists();

        if ($sigueEnEspera) {
            return;
        }

        Expediente::query()
            ->where('persona_id', $persona->id)
            ->where('motivo_bloqueo', self::MOTIVO_BLOQUEO)
            ->update(['estado' => 'activo', 'motivo_bloqueo' => null]);
    }
}
