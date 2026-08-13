<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un conjunto de instrumentos que se aplican juntos.
 *
 * NO lleva el trait de tenant: con `organizacion_id` NULL es plantilla del
 * sistema y el global scope la escondería, dejando a los tenants sin baterías
 * de arranque.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $clave
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $orden_instrumentos
 * @property bool $permite_pausas
 * @property int|null $tiempo_total_min
 * @property string $estado
 */
class Bateria extends Modelo
{
    protected $table = 'baterias';

    protected $fillable = [
        'organizacion_id', 'clave', 'nombre', 'descripcion',
        'orden_instrumentos', 'permite_pausas', 'tiempo_total_min', 'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permite_pausas' => 'boolean'];
    }

    /** @return HasMany<BateriaInstrumento, $this> */
    public function instrumentos(): HasMany
    {
        return $this->hasMany(BateriaInstrumento::class)->orderBy('orden');
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activa';
    }

    /**
     * Las plantillas del sistema MÁS las propias del tenant.
     *
     * @param  Builder<Bateria>  $consulta
     * @return Builder<Bateria>
     */
    public function scopeDisponiblesPara(Builder $consulta, ?int $organizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($organizacionId): void {
            $sub->whereNull('organizacion_id');

            if ($organizacionId !== null) {
                $sub->orWhere('organizacion_id', $organizacionId);
            }
        });
    }
}
