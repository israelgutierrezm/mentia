<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ValidezDetalle;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 1 — validez previa (Doc 05 §2).
 *
 * Va PRIMERO por una razón: un protocolo contestado al azar produce puntajes
 * perfectamente calculables, y si se califica antes de mirar la validez, esos
 * números entran al expediente con la misma apariencia que los buenos.
 *
 * `fallo` → inválida y el pipeline se detiene.
 * `advertencia` → dudosa, sigue, y los reportes lo dicen.
 */
class EtapaValidez extends EtapaDelPipeline
{
    protected function etapa(): string
    {
        return 'validez';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        foreach ($this->estrategiasConfiguradas($contexto) as $configurada) {
            $configurada['estrategia']->ejecutar($contexto, $configurada['parametros']);
        }

        DB::transaction(function () use ($contexto): void {
            // Recalificar vuelve a escribir el veredicto: dejar el detalle
            // anterior mezclado con el nuevo haría imposible saber cuál explica
            // el estado actual.
            ValidezDetalle::query()->where('aplicacion_id', $contexto->aplicacion->id)->delete();

            foreach ($contexto->validez as $hallazgo) {
                ValidezDetalle::query()->create([
                    'aplicacion_id' => $contexto->aplicacion->id,
                    ...$hallazgo,
                ]);
            }

            $contexto->aplicacion->update($this->veredicto($contexto));
        });
    }

    /**
     * @return array{validez: string, motivo_invalidez: string|null}
     */
    private function veredicto(ContextoCalificacion $contexto): array
    {
        $fallos = array_filter(
            $contexto->validez,
            static fn (array $hallazgo): bool => $hallazgo['resultado'] === 'fallo',
        );

        if ($fallos !== []) {
            return [
                'validez' => 'invalida',
                'motivo_invalidez' => substr(
                    implode(' ', array_column($fallos, 'detalle')),
                    0,
                    255,
                ),
            ];
        }

        $advertencias = array_filter(
            $contexto->validez,
            static fn (array $hallazgo): bool => $hallazgo['resultado'] === 'advertencia',
        );

        if ($advertencias !== []) {
            return [
                'validez' => 'dudosa',
                'motivo_invalidez' => substr(
                    implode(' ', array_column($advertencias, 'detalle')),
                    0,
                    255,
                ),
            ];
        }

        return ['validez' => 'valida', 'motivo_invalidez' => null];
    }
}
