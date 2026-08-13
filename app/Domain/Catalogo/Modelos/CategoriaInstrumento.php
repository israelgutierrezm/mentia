<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría o subcategoría, según tenga padre. Una sola tabla porque el
 * esquema es el mismo y dos idénticas obligarían a duplicar cada consulta.
 *
 * @property int $id
 * @property int|null $padre_id
 * @property string $clave
 * @property string $nombre
 * @property int $orden
 */
class CategoriaInstrumento extends Modelo
{
    protected $table = 'categorias_instrumento';

    protected $fillable = ['padre_id', 'clave', 'nombre', 'orden'];

    /** @return BelongsTo<CategoriaInstrumento, $this> */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    /** @return HasMany<CategoriaInstrumento, $this> */
    public function subcategorias(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id');
    }

    public function esSubcategoria(): bool
    {
        return $this->padre_id !== null;
    }
}
