<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global. Ajusta el vocabulario de la interfaz sin cambiar el
 * esquema: la misma tabla `agrupaciones` es "grupo" en una escuela y "vacante"
 * en una empresa.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string $vocabulario_persona
 * @property string $vocabulario_agrupacion
 */
class TipoOrganizacion extends Modelo
{
    protected $table = 'tipos_organizacion';

    protected $fillable = [
        'clave',
        'nombre',
        'vocabulario_persona',
        'vocabulario_agrupacion',
    ];

    /**
     * @return HasMany<Organizacion, $this>
     */
    public function organizaciones(): HasMany
    {
        return $this->hasMany(Organizacion::class);
    }
}
