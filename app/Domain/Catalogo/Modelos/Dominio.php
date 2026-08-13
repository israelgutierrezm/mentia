<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * Los "órganos" del expediente: la dimensión por la que el perfil longitudinal
 * compara a lo largo de la vida.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property int $orden
 */
class Dominio extends Modelo
{
    protected $table = 'dominios';

    protected $fillable = ['clave', 'nombre', 'orden'];
}
