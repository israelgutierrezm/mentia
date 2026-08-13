<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Catalogo\Modelos\Escala;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un criterio del perfil: qué escala, en qué rango y cuánto pesa.
 *
 * @property int $id
 * @property int $perfil_puesto_id
 * @property int $escala_id
 * @property string $tipo_puntaje
 * @property float|null $valor_min
 * @property float|null $valor_max
 * @property float $ponderacion
 */
class PerfilPuestoCriterio extends Modelo
{
    protected $table = 'perfil_puesto_criterios';

    protected $fillable = [
        'perfil_puesto_id', 'escala_id', 'tipo_puntaje',
        'valor_min', 'valor_max', 'ponderacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valor_min' => 'float',
            'valor_max' => 'float',
            'ponderacion' => 'float',
        ];
    }

    /** @return BelongsTo<PerfilPuesto, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilPuesto::class, 'perfil_puesto_id');
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    public function cumple(float $valor): bool
    {
        if ($this->valor_min !== null && $valor < $this->valor_min) {
            return false;
        }

        return ! ($this->valor_max !== null && $valor > $this->valor_max);
    }
}
