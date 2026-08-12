<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El conjunto al que se le lanza una evaluación.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int|null $unidad_id
 * @property int $tipo_agrupacion_id
 * @property string $nombre
 * @property \Illuminate\Support\Carbon|null $periodo_inicio
 * @property \Illuminate\Support\Carbon|null $periodo_fin
 * @property string $estado
 */
class Agrupacion extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'agrupaciones';

    protected $fillable = [
        'organizacion_id',
        'unidad_id',
        'tipo_agrupacion_id',
        'nombre',
        'periodo_inicio',
        'periodo_fin',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    /**
     * @return BelongsTo<TipoAgrupacion, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoAgrupacion::class, 'tipo_agrupacion_id');
    }

    /**
     * @return HasMany<AgrupacionMiembro, $this>
     */
    public function miembros(): HasMany
    {
        return $this->hasMany(AgrupacionMiembro::class);
    }

    /**
     * Sólo las membresías VIGENTES.
     *
     * Es la relación que debe usar cualquier cosa que decida acceso o
     * destinatarios: `miembros()` incluye a quien ya se dio de baja, y un
     * docente que dejó el grupo en julio no puede seguir viendo sus
     * resultados en septiembre.
     *
     * @return HasMany<AgrupacionMiembro, $this>
     */
    public function miembrosVigentes(): HasMany
    {
        return $this->miembros()->whereNull('fecha_baja');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Persona, $this>
     */
    public function personas(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'agrupacion_miembros')
            ->withPivot(['rol_en_agrupacion', 'fecha_alta', 'fecha_baja']);
    }
}
