<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;

/**
 * Un parámetro de la organización, una fila.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property string $clave
 * @property string|null $valor
 */
class OrganizacionConfiguracion extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'organizacion_configuraciones';

    protected $fillable = ['organizacion_id', 'clave', 'valor'];
}
