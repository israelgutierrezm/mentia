<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El perfil esperado de un puesto (Doc 05 §4).
 *
 * Del tenant siempre: el "supervisor de piso" de una empresa no es el de otra
 * aunque se llamen igual.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activo
 */
class PerfilPuesto extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'perfiles_puesto';

    protected $fillable = ['organizacion_id', 'nombre', 'descripcion', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** @return HasMany<PerfilPuestoCriterio, $this> */
    public function criterios(): HasMany
    {
        return $this->hasMany(PerfilPuestoCriterio::class);
    }
}
