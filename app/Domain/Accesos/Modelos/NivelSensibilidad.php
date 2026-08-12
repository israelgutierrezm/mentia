<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * Catálogo global de niveles de sensibilidad (1 general … 4 clínico).
 *
 * @property int $id
 * @property int $nivel
 * @property string $clave
 * @property string $nombre
 * @property string $descripcion
 */
class NivelSensibilidad extends Modelo
{
    protected $table = 'niveles_sensibilidad';

    protected $fillable = ['nivel', 'clave', 'nombre', 'descripcion'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nivel' => 'integer'];
    }
}
