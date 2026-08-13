<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

/**
 * `audit_zonas` (Doc 05 §2).
 *
 * Las cuatro zonas de riesgo del AUDIT de la OMS: 0–7 zona I (educación),
 * 8–15 zona II (consejo breve), 16–19 zona III (consejo más monitoreo),
 * 20–40 zona IV (derivación a evaluación especializada).
 *
 * Las zonas no son grados de un mismo problema: cada una tiene una acción
 * distinta asociada, y por eso el instrumento clasifica en vez de puntuar.
 */
class ZonasAudit extends CortesPorTramos
{
    public static function clave(): string
    {
        return 'audit_zonas';
    }

    protected function tramosPorOmision(): array
    {
        return [
            [0, 'zona_I'],
            [8, 'zona_II'],
            [16, 'zona_III'],
            [20, 'zona_IV'],
        ];
    }
}
