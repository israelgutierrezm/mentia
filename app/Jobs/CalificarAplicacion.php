<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * STUB de la Fase 6 (Doc 08: "finalizar → job de calificación (stub)").
 *
 * El pipeline real —las seis etapas del Doc 05 §2 encadenadas— es la Fase 7.
 * Esto existe para que el motor pueda encolar al finalizar sin conocer todavía
 * lo que viene después: cuando la Fase 7 llegue, sustituye el `handle()` y el
 * motor no se entera.
 *
 * Va en la cola `calificacion` (Doc 02 §7): es el grueso del trabajo y no debe
 * competir con las alertas.
 *
 * Lleva el ID, no el modelo: un job serializado con la aplicación entera
 * guardaría sus atributos en la tabla `jobs`, y ahí no tiene nada que hacer
 * material de un expediente.
 */
class CalificarAplicacion implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $aplicacionId)
    {
        $this->onQueue('calificacion');
    }

    public function handle(): void
    {
        $aplicacion = Aplicacion::query()
            ->withoutGlobalScopes()
            ->find($this->aplicacionId);

        if ($aplicacion === null) {
            return;
        }

        Log::info('Pipeline de calificación pendiente (Fase 7)', [
            'aplicacion_id' => $aplicacion->id,
            'version_instrumento_id' => $aplicacion->version_instrumento_id,
            'respuestas' => $aplicacion->respuestas()->count(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['calificacion', 'aplicacion:'.$this->aplicacionId];
    }
}
