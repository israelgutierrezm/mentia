<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

/**
 * `phq_gravedad` (Doc 05 §2).
 *
 * Los cortes del PHQ-9 de Kroenke, Spitzer y Williams (2001): 0–4 mínima,
 * 5–9 leve, 10–14 moderada, 15–19 moderadamente grave, 20–27 grave.
 *
 * Son puntos de corte de TAMIZAJE, no un diagnóstico. La categoría dice qué
 * tan probable es que valga la pena una entrevista clínica; quien diagnostica
 * es la persona que la hace.
 */
class GravedadPhq extends CortesPorTramos
{
    public static function clave(): string
    {
        return 'phq_gravedad';
    }

    protected function tramosPorOmision(): array
    {
        return [
            [0, 'minima'],
            [5, 'leve'],
            [10, 'moderada'],
            [15, 'moderadamente_grave'],
            [20, 'grave'],
        ];
    }
}
