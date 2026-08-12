<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membresía con vigencia.
 *
 * NO lleva `organizacion_id` —el diccionario no se lo da— y por tanto tampoco
 * el trait de tenant: se acota SIEMPRE por su agrupación, que sí lo lleva. Es
 * lo que vigila la suite de aislamiento: cualquier consulta que llegue aquí
 * sin pasar por `agrupaciones` sería una fuga.
 *
 * @property int $id
 * @property int $agrupacion_id
 * @property int $persona_id
 * @property string $rol_en_agrupacion
 * @property \Illuminate\Support\Carbon $fecha_alta
 * @property \Illuminate\Support\Carbon|null $fecha_baja
 */
class AgrupacionMiembro extends Modelo
{
    protected $table = 'agrupacion_miembros';

    protected $fillable = [
        'agrupacion_id',
        'persona_id',
        'rol_en_agrupacion',
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
     * @return BelongsTo<Agrupacion, $this>
     */
    public function agrupacion(): BelongsTo
    {
        return $this->belongsTo(Agrupacion::class);
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function estaVigente(): bool
    {
        return $this->fecha_baja === null;
    }

    /**
     * @param  Builder<AgrupacionMiembro>  $consulta
     * @return Builder<AgrupacionMiembro>
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->whereNull('fecha_baja');
    }
}
