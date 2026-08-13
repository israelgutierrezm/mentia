<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `suma_simple` (Doc 05 §2).
 *
 * El VALOR de la respuesta suma a la escala del reactivo. Es el PHQ-9, el
 * GAD-7, el WHO-5: la opción vale 0, 1, 2 o 3 y el puntaje es la suma.
 *
 * La escala a la que pertenece el reactivo se declara con una clave de
 * calificación SIN opción (`opcion_id` NULL): "este reactivo pertenece a esta
 * escala". Con `peso` distinto de 1 la suma queda ponderada por reactivo, que
 * es lo que distingue esto de `suma_ponderada` —donde el peso lo lleva cada
 * opción—.
 */
class SumaSimple extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'suma_simple';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);

        foreach ($contexto->reactivos as $reactivo) {
            $respuesta = $contexto->respuestasDe($reactivo->id)->first();

            if ($respuesta === null || $respuesta->valor_numerico === null) {
                continue;
            }

            $valor = $this->valorReflejado($reactivo, (float) $respuesta->valor_numerico);

            foreach ($claves[$reactivo->id] ?? [] as $clave) {
                if ($clave->opcion_id !== null) {
                    continue;
                }

                $this->acumular($contexto, $clave->escala_id, $valor * (float) $clave->peso);
            }
        }

        $this->completarEnCero($contexto);
    }
}
