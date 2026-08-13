<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * Cuánto tiene que moverse un constructo para que el cambio importe.
 *
 * Que un percentil suba de 40 a 45 es ruido de medición. Lo que hay que
 * marcarle al profesional es el cambio que sale del error de medida, y cuánto
 * es eso depende del constructo y de la escala en que se mide. Sin umbral, o se
 * marca todo —y entonces nadie mira las marcas— o no se marca nada.
 *
 * No lleva el trait de tenant: la resolución mira primero el de la organización
 * y cae al de la plataforma (`organizacion_id` NULL), y el global scope, que
 * falla cerrado, escondería justamente el de respaldo.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $constructo
 * @property string $tipo_norma
 * @property float $delta_minimo
 */
class UmbralCambio extends Modelo
{
    protected $table = 'umbrales_cambio';

    protected $fillable = ['organizacion_id', 'constructo', 'tipo_norma', 'delta_minimo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delta_minimo' => 'float'];
    }

    /**
     * Los que la plataforma trae de fábrica, por tipo de norma.
     *
     * Una desviación estándar en cada escala: 10 en T, 15 en CI, 2 en decatipo
     * y estanina. En percentiles no hay DE que valga —la escala no es de
     * intervalo— así que se usan 20 puntos, que es el salto entre categorías
     * de casi cualquier interpretación por cuartiles.
     */
    public const POR_OMISION = [
        'T' => 10.0,
        'ci_desviacion' => 15.0,
        'decatipo' => 2.0,
        'estanina' => 2.0,
        'percentil' => 20.0,
        'semaforo' => 1.0,
    ];
}
