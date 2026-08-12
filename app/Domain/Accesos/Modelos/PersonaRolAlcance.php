<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sobre QUIÉN puede ejercer una persona su rol.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int $persona_id
 * @property int $rol_id
 * @property string $alcance_tipo
 * @property int $alcance_id
 * @property Carbon $vigencia_inicio
 * @property Carbon|null $vigencia_fin
 * @property int|null $otorgado_por
 */
class PersonaRolAlcance extends Modelo
{
    use PerteneceAOrganizacion;

    public const TIPO_ORGANIZACION = 'organizacion';

    public const TIPO_UNIDAD = 'unidad';

    public const TIPO_AGRUPACION = 'agrupacion';

    public const TIPO_PERSONA = 'persona';

    protected $table = 'persona_rol_alcances';

    protected $fillable = [
        'organizacion_id',
        'persona_id',
        'rol_id',
        'alcance_tipo',
        'alcance_id',
        'vigencia_inicio',
        'vigencia_fin',
        'otorgado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'date',
            'vigencia_fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<Rol, $this>
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function estaVigente(?Carbon $al = null): bool
    {
        $fecha = $al ?? Carbon::now();

        if ($this->vigencia_inicio->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_fin === null
            || $this->vigencia_fin->endOfDay()->greaterThanOrEqualTo($fecha);
    }

    /**
     * @param  Builder<PersonaRolAlcance>  $consulta
     * @return Builder<PersonaRolAlcance>
     */
    public function scopeVigentes(Builder $consulta, ?Carbon $al = null): Builder
    {
        $fecha = $al ?? Carbon::now();

        return $consulta
            ->whereDate('vigencia_inicio', '<=', $fecha)
            ->where(function (Builder $sub) use ($fecha): void {
                $sub->whereNull('vigencia_fin')
                    ->orWhereDate('vigencia_fin', '>=', $fecha);
            });
    }
}
