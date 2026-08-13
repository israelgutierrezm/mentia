<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipología: perfiles Cleaver, código RIASEC de tres letras, tipos DISC.
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion_profesional
 * @property string|null $descripcion_evaluado
 * @property string|null $fortalezas
 * @property string|null $areas_desarrollo
 * @property int $orden
 */
class PerfilTipo extends Modelo
{
    protected $table = 'perfiles_tipo';

    protected $fillable = [
        'version_instrumento_id', 'codigo', 'nombre', 'descripcion_profesional',
        'descripcion_evaluado', 'fortalezas', 'areas_desarrollo', 'orden',
    ];

    /** @return HasMany<PerfilTipoCondicion, $this> */
    public function condiciones(): HasMany
    {
        return $this->hasMany(PerfilTipoCondicion::class, 'perfil_tipo_id');
    }

    /** @return BelongsToMany<Ocupacion, $this> */
    public function ocupaciones(): BelongsToMany
    {
        return $this->belongsToMany(Ocupacion::class, 'perfil_tipo_ocupaciones');
    }
}
