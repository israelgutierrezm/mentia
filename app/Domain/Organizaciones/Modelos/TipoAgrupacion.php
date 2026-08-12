<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grupo escolar, vacante, generación, taller, cohorte, centro de trabajo.
 *
 * NO lleva el trait PerteneceAOrganizacion: con `organizacion_id` NULL es una
 * plantilla del sistema visible para todos los tenants, y el global scope la
 * escondería. El acotamiento va en el scope `disponiblesPara()`, que suma las
 * plantillas del sistema a las propias del tenant.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $clave
 * @property string $nombre
 */
class TipoAgrupacion extends Modelo
{
    protected $table = 'tipos_agrupacion';

    protected $fillable = ['organizacion_id', 'clave', 'nombre'];

    /**
     * @return BelongsTo<Organizacion, $this>
     */
    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class);
    }

    public function esPlantillaDelSistema(): bool
    {
        return $this->organizacion_id === null;
    }

    /**
     * Las plantillas del sistema MÁS los tipos propios de esa organización.
     *
     * @param  Builder<TipoAgrupacion>  $consulta
     * @return Builder<TipoAgrupacion>
     */
    public function scopeDisponiblesPara(Builder $consulta, int $organizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($organizacionId): void {
            $sub->whereNull('organizacion_id')
                ->orWhere('organizacion_id', $organizacionId);
        });
    }
}
