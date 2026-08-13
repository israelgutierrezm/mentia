<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Catalogo\Modelos\CentinelaCondicion;
use App\Domain\Evaluaciones\Datos\DisparoCentinela;
use App\Domain\Evaluaciones\Modelos\Respuesta;

/**
 * Evalúa los centinelas de un lote de respuestas.
 *
 * Corre SÍNCRONO, con la aplicación en curso. No se encola: una alerta que
 * espera en cola no es una alerta (Doc 02 §2, regla 4).
 *
 * Sólo mira los reactivos marcados `es_centinela`, que en un instrumento son
 * uno o dos: el costo de esto en cada lote es una consulta por índice, no un
 * recorrido.
 */
class EvaluadorCentinelas
{
    /**
     * @param  list<Respuesta>  $respuestas
     * @return list<DisparoCentinela>
     */
    public function evaluar(array $respuestas): array
    {
        if ($respuestas === []) {
            return [];
        }

        $idsCentinela = $this->centinelasDelLote($respuestas);

        if ($idsCentinela === []) {
            return [];
        }

        $condiciones = CentinelaCondicion::query()
            ->whereIn('reactivo_id', $idsCentinela)
            ->get()
            ->groupBy('reactivo_id');

        $disparos = [];

        foreach ($respuestas as $respuesta) {
            $delReactivo = $condiciones->get($respuesta->reactivo_id);

            if ($delReactivo === null) {
                continue;
            }

            $valor = $respuesta->valor_numerico !== null
                ? (float) $respuesta->valor_numerico
                : null;

            foreach ($delReactivo as $condicion) {
                if (! $condicion->disparaCon($respuesta->opcion_id, $valor)) {
                    continue;
                }

                $disparos[] = new DisparoCentinela(
                    respuesta: $respuesta,
                    condicion: $condicion,
                );

                /*
                 * Una sola alerta por reactivo aunque encajen dos condiciones.
                 * Duplicar la alerta no aporta información y sí ruido en una
                 * bandeja donde el ruido hace que se ignoren las verdaderas.
                 */
                break;
            }
        }

        return $disparos;
    }

    /**
     * @param  list<Respuesta>  $respuestas
     * @return list<int>
     */
    private function centinelasDelLote(array $respuestas): array
    {
        $ids = [];

        foreach ($respuestas as $respuesta) {
            $reactivo = $respuesta->reactivo;

            if ($reactivo !== null && $reactivo->es_centinela) {
                $ids[] = $reactivo->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
