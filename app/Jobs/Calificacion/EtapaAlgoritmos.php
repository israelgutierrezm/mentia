<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 3 — algoritmos especiales (Doc 05 §2).
 *
 * Lo que no se resuelve sumando: los cortes oficiales de la NOM-035, las zonas
 * del AUDIT, las dos etapas del M-CHAT. Cada uno es una clase registrada, así
 * que agregar el siguiente no toca el pipeline.
 *
 * Su salida no es un número: es una CATEGORÍA. Y esa categoría se guarda como
 * `semaforo` sobre la escala, con precedencia sobre lo que pudiera decir un
 * baremo — los cortes de la NOM-035 los publicó el DOF, no se re-normalizan
 * contra una tabla de percentiles.
 */
class EtapaAlgoritmos extends EtapaDelPipeline
{
    protected function etapa(): string
    {
        return 'algoritmos';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        $configuradas = $this->estrategiasConfiguradas($contexto);

        if ($configuradas === []) {
            return;
        }

        foreach ($configuradas as $configurada) {
            $configurada['estrategia']->ejecutar($contexto, $configurada['parametros']);
        }

        $this->persistir($contexto);
    }

    private function persistir(ContextoCalificacion $contexto): void
    {
        $ahora = Carbon::now();

        DB::transaction(function () use ($contexto, $ahora): void {
            // Un algoritmo puede haber cambiado el bruto —el M-CHAT recalifica
            // tras la entrevista de seguimiento—, así que se vuelve a escribir.
            foreach ($contexto->brutos as $escalaId => $bruto) {
                if ($contexto->escalas->firstWhere('id', $escalaId) === null) {
                    continue;
                }

                ResultadoEscala::query()->updateOrCreate(
                    ['aplicacion_id' => $contexto->aplicacion->id, 'escala_id' => $escalaId],
                    ['puntaje_bruto' => round($bruto, 3), 'calculado_en' => $ahora],
                );
            }

            foreach ($contexto->etiquetas as $claveEscala => $etiqueta) {
                $escala = $contexto->escalaPorClave($claveEscala);

                if ($escala === null) {
                    continue;
                }

                ResultadoEscala::query()
                    ->where('aplicacion_id', $contexto->aplicacion->id)
                    ->where('escala_id', $escala->id)
                    ->update([
                        'tipo_norma' => 'semaforo',
                        'etiqueta_norma' => $etiqueta,
                        'valor_normalizado' => null,
                        'sin_norma' => false,
                    ]);
            }
        });
    }
}
