<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * Crosswalk O*NET para el vocacional.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string|null $codigo_riasec
 */
class Ocupacion extends Modelo
{
    protected $table = 'ocupaciones';

    protected $fillable = ['clave', 'nombre', 'codigo_riasec', 'descripcion'];
}
