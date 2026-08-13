<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use App\Domain\Interpretacion\Servicios\ResolutorInterpretaciones;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 5 — interpretación (Doc 05 §2).
 *
 * Convierte números en texto, y lo hace SIEMPRE con lo que alguien escribió en
 * el catálogo. Aquí no se redacta nada: el sistema sugiere y el profesional
 * diagnostica (principio P6).
 *
 * Cada regla trae su audiencia, así que un mismo resultado produce el texto
 * técnico para el profesional y el cuidado para quien contestó — nunca el
 * mismo con distinto formato.
 */
class EtapaInterpretacion extends EtapaDelPipeline
{
    protected function etapa(): string
    {
        return 'interpretacion';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        $resueltas = app(ResolutorInterpretaciones::class)->resolver($contexto);

        DB::transaction(function () use ($contexto, $resueltas): void {
            // Recalificar reemplaza: mezclar los textos de dos corridas dejaría
            // interpretaciones contradictorias en el mismo expediente.
            ResultadoInterpretacion::query()
                ->where('aplicacion_id', $contexto->aplicacion->id)
                ->delete();

            foreach ($resueltas as $interpretacion) {
                ResultadoInterpretacion::query()->create([
                    'aplicacion_id' => $contexto->aplicacion->id,
                    ...$interpretacion,
                ]);
            }
        });
    }
}
