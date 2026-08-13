<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Validez;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 1 — `tiempo_atipico` (Doc 05 §2).
 *
 * Se mira la MEDIANA por reactivo, no el total ni el promedio. El total lo
 * arruina cualquier interrupción —el teléfono, la puerta—, y el promedio lo
 * arruina un solo reactivo en el que la persona se quedó pensando quince
 * minutos. La mediana sobrevive a las dos cosas.
 *
 * Demasiado rápido es respuesta al azar; demasiado lento, en una prueba de
 * ejecución, suele ser ayuda de alguien más.
 *
 * Parámetros: `ms_min` (por omisión 800), `ms_max` (por omisión 120000).
 */
class TiempoAtipico implements EstrategiaCalificacion
{
    public static function clave(): string
    {
        return 'tiempo_atipico';
    }

    public static function etapa(): string
    {
        return 'validez';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $tiempos = $contexto->respuestas
            ->pluck('tiempo_respuesta_ms')
            ->filter(static fn (?int $ms): bool => $ms !== null && $ms > 0)
            ->map(static fn (int $ms): int => $ms)
            ->sort()
            ->values();

        if ($tiempos->count() < 5) {
            /*
             * Con menos de cinco tiempos la mediana no dice nada. No se declara
             * "paso" —no se comprobó— y tampoco se falla: se deja constancia de
             * que no se pudo verificar, que es distinto de que esté bien.
             */
            $contexto->anotarValidez(
                'tiempo_atipico',
                'paso',
                'Sin tiempos suficientes para evaluar la mediana.',
            );

            return;
        }

        $mediana = $this->mediana($tiempos->all());

        $minimo = (float) ($parametros['ms_min'] ?? 800);
        $maximo = (float) ($parametros['ms_max'] ?? 120000);

        if ($mediana < $minimo) {
            $contexto->anotarValidez(
                'tiempo_atipico',
                'advertencia',
                sprintf(
                    'Mediana de %s ms por reactivo, por debajo de los %s ms esperados: posible respuesta al azar.',
                    round($mediana),
                    round($minimo),
                ),
            );

            return;
        }

        if ($mediana > $maximo) {
            $contexto->anotarValidez(
                'tiempo_atipico',
                'advertencia',
                sprintf(
                    'Mediana de %s ms por reactivo, por encima de los %s ms esperados.',
                    round($mediana),
                    round($maximo),
                ),
            );

            return;
        }

        $contexto->anotarValidez(
            'tiempo_atipico',
            'paso',
            sprintf('Mediana de %s ms por reactivo, dentro de lo esperado.', round($mediana)),
        );
    }

    /**
     * @param  list<int>  $ordenados
     */
    private function mediana(array $ordenados): float
    {
        $cuantos = count($ordenados);
        $medio = intdiv($cuantos, 2);

        if ($cuantos % 2 === 1) {
            return (float) $ordenados[$medio];
        }

        return ($ordenados[$medio - 1] + $ordenados[$medio]) / 2;
    }
}
