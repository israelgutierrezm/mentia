<?php

declare(strict_types=1);

namespace App\Domain\Personas\Modelos;

use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El vínculo persona ↔ tenant.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int $persona_id
 * @property string|null $matricula_o_num_empleado
 * @property string $estado
 * @property string $origen_alta
 * @property Carbon $fecha_alta
 * @property Carbon|null $fecha_baja
 */
class OrganizacionPersona extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'organizacion_personas';

    protected $fillable = [
        'organizacion_id',
        'persona_id',
        'matricula_o_num_empleado',
        'estado',
        'origen_alta',
        'fecha_alta',
        'fecha_baja',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_alta' => 'date',
            'fecha_baja' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activa';
    }

    /**
     * @param  Builder<OrganizacionPersona>  $consulta
     * @return Builder<OrganizacionPersona>
     */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('estado', 'activa');
    }
}
