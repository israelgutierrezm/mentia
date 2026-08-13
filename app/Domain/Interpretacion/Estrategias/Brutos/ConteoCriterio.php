<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `conteo_criterio` (Doc 05 §2): M-CHAT, Guía de Referencia I.
 *
 * Cuenta cuántos reactivos CUMPLEN una condición de riesgo, en vez de sumar
 * valores. En un M-CHAT el puntaje no es la suma de las respuestas: es cuántas
 * salieron en la dirección de riesgo, y para unos reactivos eso es "sí" y para
 * otros "no".
 *
 * La dirección de riesgo de cada reactivo la declara su clave: `peso` distinto
 * de cero marca la opción que cuenta. Así el instrumento decide qué es riesgo,
 * no la estrategia.
 */
class ConteoCriterio extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'conteo_criterio';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);

        foreach ($contexto->respuestas as $respuesta) {
            foreach ($claves[$respuesta->reactivo_id] ?? [] as $clave) {
                if ($clave->rol !== 'normal') {
                    continue;
                }

                if (! $this->cumple($clave, $respuesta->opcion_id, $respuesta->valor_numerico)) {
                    continue;
                }

                // Cuenta UNO, no el peso: es un conteo. El peso sólo decide si
                // esa opción cuenta o no.
                $this->acumular($contexto, $clave->escala_id, 1.0);
            }
        }

        $this->completarEnCero($contexto);
    }

    private function cumple(ClaveCalificacion $clave, ?int $opcionId, mixed $valorNumerico): bool
    {
        if ((float) $clave->peso === 0.0) {
            return false;
        }

        if ($clave->opcion_id !== null) {
            return $clave->opcion_id === $opcionId;
        }

        // Clave sin opción: cuenta cualquier respuesta con valor distinto de
        // cero. Es la forma de expresar "cualquier presencia del síntoma".
        return $valorNumerico !== null && (float) $valorNumerico !== 0.0;
    }
}
