<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un catálogo de opciones reutilizable por varios campos (entidades
 * federativas, tipo de sangre, escolaridad).
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 */
class CatalogoOpciones extends Modelo
{
    protected $table = 'catalogos_opciones';

    protected $fillable = ['clave', 'nombre'];

    /**
     * @return HasMany<OpcionCatalogo, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionCatalogo::class, 'catalogo_opciones_id')
            ->where('activo', true)
            ->orderBy('orden');
    }
}
