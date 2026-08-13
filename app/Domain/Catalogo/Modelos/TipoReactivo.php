<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * Catálogo extensible. Cada clave mapea a un componente de render del motor
 * de aplicación (Fase 6).
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property bool $requiere_opciones
 * @property bool $admite_multimedia
 */
class TipoReactivo extends Modelo
{
    protected $table = 'tipos_reactivo';

    protected $fillable = ['clave', 'nombre', 'requiere_opciones', 'admite_multimedia'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requiere_opciones' => 'boolean', 'admite_multimedia' => 'boolean'];
    }
}
