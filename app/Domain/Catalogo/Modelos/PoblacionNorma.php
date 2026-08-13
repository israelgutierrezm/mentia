<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * @property int $id
 * @property string $clave
 * @property string $nombre
 */
class PoblacionNorma extends Modelo
{
    protected $table = 'poblaciones_norma';

    protected $fillable = ['clave', 'nombre', 'pais', 'descripcion', 'fuente'];
}
