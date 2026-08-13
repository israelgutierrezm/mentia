<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una regla opción → escala. `rol` es lo que hace posibles los ipsativos: la
 * misma opción puntúa a una escala como "más" y a otra como "menos".
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int $reactivo_id
 * @property int|null $opcion_id
 * @property int $escala_id
 * @property string $peso
 * @property string $rol
 */
class ClaveCalificacion extends Modelo
{
    protected $table = 'claves_calificacion';

    protected $fillable = [
        'version_instrumento_id', 'reactivo_id', 'opcion_id', 'escala_id', 'peso', 'rol',
    ];

    /** @return BelongsTo<Reactivo, $this> */
    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class);
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    /** @return BelongsTo<OpcionReactivo, $this> */
    public function opcion(): BelongsTo
    {
        return $this->belongsTo(OpcionReactivo::class, 'opcion_id');
    }
}
