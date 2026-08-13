<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $perfil_tipo_id
 * @property int $escala_id
 * @property string $tipo_puntaje
 * @property string $operador
 * @property string|null $valor_min
 * @property string|null $valor_max
 * @property string $conector
 */
class PerfilTipoCondicion extends Modelo
{
    protected $table = 'perfil_tipo_condiciones';

    protected $fillable = [
        'perfil_tipo_id', 'escala_id', 'tipo_puntaje', 'operador',
        'valor_min', 'valor_max', 'conector',
    ];

    /** @return BelongsTo<PerfilTipo, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilTipo::class, 'perfil_tipo_id');
    }
}
