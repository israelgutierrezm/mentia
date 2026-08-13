<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property bool $requiere_validacion
 */
class TipoDocumento extends Modelo
{
    protected $table = 'tipos_documento';

    protected $fillable = ['clave', 'nombre', 'requiere_validacion'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requiere_validacion' => 'boolean'];
    }
}
