<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `suma_ponderada` (Doc 05 §2): el peso lo lleva LA OPCIÓN.
 *
 * Es la plantilla de calificación clásica: marcar "de acuerdo" en el reactivo
 * 17 suma 2 a Extroversión y nada a lo demás. Cada opción de cada reactivo
 * tiene su renglón en `claves_calificacion` con su escala y su peso.
 *
 * A diferencia de `suma_simple`, el valor numérico de la respuesta no importa:
 * lo que importa es QUÉ opción se marcó. Por eso aquí la reflexión de los
 * reactivos inversos no se aplica —ya está horneada en los pesos de la clave, y
 * aplicarla otra vez la invertiría de vuelta—.
 */
class SumaPonderada extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'suma_ponderada';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);

        foreach ($contexto->respuestas as $respuesta) {
            if ($respuesta->opcion_id === null) {
                continue;
            }

            foreach ($claves[$respuesta->reactivo_id] ?? [] as $clave) {
                if ($clave->opcion_id !== $respuesta->opcion_id) {
                    continue;
                }

                // Los ipsativos tienen su propia estrategia: aquí sólo lo normal.
                if ($clave->rol !== 'normal') {
                    continue;
                }

                $this->acumular($contexto, $clave->escala_id, (float) $clave->peso);
            }
        }

        $this->completarEnCero($contexto);
    }
}
