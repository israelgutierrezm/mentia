<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El tenant.
 *
 * NO usa el trait PerteneceAOrganizacion: es la tabla que define el
 * discriminador, no una que lo lleve. Acotarla a sí misma sería circular, y
 * quien decide qué organizaciones ve alguien es la plataforma, no el scope.
 *
 * @property int $id
 * @property string $nombre
 * @property int $tipo_organizacion_id
 * @property string|null $rfc
 * @property string $estado
 * @property string $zona_horaria
 */
class Organizacion extends Modelo
{
    protected $table = 'organizaciones';

    protected $fillable = [
        'nombre',
        'tipo_organizacion_id',
        'rfc',
        'estado',
        'zona_horaria',
    ];

    /**
     * @return BelongsTo<TipoOrganizacion, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacion::class, 'tipo_organizacion_id');
    }

    /**
     * @return HasMany<Unidad, $this>
     */
    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class);
    }

    /**
     * @return HasMany<Agrupacion, $this>
     */
    public function agrupaciones(): HasMany
    {
        return $this->hasMany(Agrupacion::class);
    }

    /**
     * @return HasMany<OrganizacionPersona, $this>
     */
    public function vinculaciones(): HasMany
    {
        return $this->hasMany(OrganizacionPersona::class);
    }

    /**
     * @return HasMany<OrganizacionConfiguracion, $this>
     */
    public function configuraciones(): HasMany
    {
        return $this->hasMany(OrganizacionConfiguracion::class);
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activa';
    }
}
