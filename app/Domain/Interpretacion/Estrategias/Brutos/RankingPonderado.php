<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `ranking_ponderado` (Doc 05 §2): Zavic, Allport.
 *
 * La persona ordena las opciones y la POSICIÓN vale puntos: el primer lugar
 * vale N, el último 1. Es la conversión que hace comparable un orden con una
 * suma.
 *
 * Parámetro `puntos_primero`: los puntos del primer lugar. Por omisión, el
 * número de opciones del propio reactivo, que es la escala clásica de Zavic
 * (4-3-2-1 en cuadros de cuatro).
 */
class RankingPonderado extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'ranking_ponderado';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);

        foreach ($contexto->reactivos as $reactivo) {
            $respuestas = $contexto->respuestasDe($reactivo->id)
                ->filter(static fn ($respuesta): bool => $respuesta->posicion_ranking !== null);

            if ($respuestas->isEmpty()) {
                continue;
            }

            $tope = isset($parametros['puntos_primero'])
                ? (int) $parametros['puntos_primero']
                : $respuestas->count();

            $delReactivo = $claves[$reactivo->id] ?? [];

            foreach ($respuestas as $respuesta) {
                $puntos = $tope - ((int) $respuesta->posicion_ranking - 1);

                // Con más opciones que puntos configurados, las últimas no
                // restan: valen cero.
                $puntos = max(0, $puntos);

                foreach ($delReactivo as $clave) {
                    if ($clave->opcion_id !== $respuesta->opcion_id) {
                        continue;
                    }

                    $this->acumular($contexto, $clave->escala_id, $puntos * (float) $clave->peso);
                }
            }
        }

        $this->completarEnCero($contexto);
    }
}
