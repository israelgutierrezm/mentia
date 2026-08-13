<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un texto de interpretación, por AUDIENCIA.
 *
 * La audiencia se deriva del rol de quien mira, jamás se elige por parámetro
 * del cliente (Doc 06 §1): si el evaluado pudiera pedir la audiencia
 * `profesional`, la vista cuidada dejaría de servir para nada.
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int|null $escala_id
 * @property string $tipo_regla
 * @property string $tipo_puntaje
 * @property string|null $operador
 * @property string|null $valor_min
 * @property string|null $valor_max
 * @property string $audiencia
 * @property string $texto_interpretacion
 * @property string|null $recomendaciones
 * @property string|null $bandera
 * @property int $prioridad
 * @property bool $vigente
 */
class ReglaInterpretacion extends Modelo
{
    protected $table = 'reglas_interpretacion';

    protected $fillable = [
        'version_instrumento_id', 'escala_id', 'tipo_regla', 'tipo_puntaje',
        'operador', 'valor_min', 'valor_max', 'audiencia', 'texto_interpretacion',
        'recomendaciones', 'bandera', 'prioridad', 'vigente',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    /** @return HasMany<ReglaInterpretacionCondicion, $this> */
    public function condiciones(): HasMany
    {
        return $this->hasMany(ReglaInterpretacionCondicion::class, 'regla_id');
    }
}
