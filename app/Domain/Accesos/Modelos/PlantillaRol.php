<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use App\Domain\Organizaciones\Modelos\TipoOrganizacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Molde global de rol. Se clona a roles del tenant al crear la organización.
 *
 * @property int $id
 * @property int|null $tipo_organizacion_id
 * @property string $clave
 * @property string $nombre
 * @property int $nivel_sensibilidad_max
 */
class PlantillaRol extends Modelo
{
    protected $table = 'plantillas_rol';

    protected $fillable = [
        'tipo_organizacion_id',
        'clave',
        'nombre',
        'nivel_sensibilidad_max',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nivel_sensibilidad_max' => 'integer'];
    }

    /**
     * @return BelongsTo<TipoOrganizacion, $this>
     */
    public function tipoOrganizacion(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacion::class, 'tipo_organizacion_id');
    }

    /**
     * @return HasMany<PlantillaRolPermiso, $this>
     */
    public function permisos(): HasMany
    {
        return $this->hasMany(PlantillaRolPermiso::class);
    }

    /**
     * Las plantillas que aplican a un tipo de organización: las suyas MÁS las
     * universales (Titular, Tutor, Auditor), que no dependen del tipo.
     *
     * @param  Builder<PlantillaRol>  $consulta
     * @return Builder<PlantillaRol>
     */
    public function scopeParaTipo(Builder $consulta, int $tipoOrganizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($tipoOrganizacionId): void {
            $sub->whereNull('tipo_organizacion_id')
                ->orWhere('tipo_organizacion_id', $tipoOrganizacionId);
        });
    }
}
