<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rango de conversión bruto → normalizado.
 *
 * La edad va en MESES y se congela al aplicar: alguien que cumple años entre
 * la aplicación y la calificación se normaliza con la edad que TENÍA.
 *
 * @property int $id
 * @property int $baremo_id
 * @property string $bruto_min
 * @property string $bruto_max
 * @property int|null $edad_min_meses
 * @property int|null $edad_max_meses
 * @property string|null $sexo
 * @property int|null $escolaridad_id
 * @property string $valor_normalizado
 * @property string|null $etiqueta
 */
class BaremoFila extends Modelo
{
    protected $table = 'baremo_filas';

    protected $fillable = [
        'baremo_id', 'bruto_min', 'bruto_max', 'edad_min_meses', 'edad_max_meses',
        'sexo', 'escolaridad_id', 'valor_normalizado', 'etiqueta',
    ];

    /** @return BelongsTo<Baremo, $this> */
    public function baremo(): BelongsTo
    {
        return $this->belongsTo(Baremo::class);
    }
}
