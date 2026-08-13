<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una condición de una combinación multi-escala. Una fila por condición: una
 * cadena con la expresión completa no se puede consultar ni validar.
 *
 * @property int $id
 * @property int $regla_id
 * @property int $escala_id
 * @property string $tipo_puntaje
 * @property string $operador
 * @property string|null $valor_min
 * @property string|null $valor_max
 * @property string $conector
 */
class ReglaInterpretacionCondicion extends Modelo
{
    protected $table = 'reglas_interpretacion_condiciones';

    protected $fillable = [
        'regla_id', 'escala_id', 'tipo_puntaje', 'operador',
        'valor_min', 'valor_max', 'conector',
    ];

    /** @return BelongsTo<ReglaInterpretacion, $this> */
    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaInterpretacion::class, 'regla_id');
    }
}
