<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Modelos;

use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Plantel, sede, departamento o área.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int|null $unidad_padre_id
 * @property string $nombre
 * @property string $tipo
 * @property string $estado
 */
class Unidad extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'unidades';

    protected $fillable = [
        'organizacion_id',
        'unidad_padre_id',
        'nombre',
        'tipo',
        'estado',
    ];

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'unidad_padre_id');
    }

    /**
     * @return HasMany<Unidad, $this>
     */
    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'unidad_padre_id');
    }

    /**
     * @return HasMany<Agrupacion, $this>
     */
    public function agrupaciones(): HasMany
    {
        return $this->hasMany(Agrupacion::class);
    }

    /**
     * Los ids de esta unidad y de TODA su descendencia.
     *
     * Es lo que hace que un alcance por unidad incluya a sus descendientes
     * (Doc 06 §1): quien coordina una sede alcanza a sus departamentos sin que
     * nadie se los enumere, y sin tener que reasignarle el alcance cada vez que
     * la organización abre un área nueva.
     *
     * Se resuelve iterando por niveles y no con una recursiva por fila: una
     * jerarquía de organización tiene tres o cuatro niveles, y así son tres o
     * cuatro consultas en vez de una por nodo.
     *
     * @return list<int>
     */
    public function idsConDescendientes(): array
    {
        $ids = [$this->id];
        $nivel = [$this->id];

        while ($nivel !== []) {
            /** @var list<int> $siguiente */
            $siguiente = static::query()
                ->whereIn('unidad_padre_id', $nivel)
                ->pluck('id')
                ->all();

            /*
             * Corta ciclos. La base admite que alguien ponga a una unidad como
             * padre de su propia ancestra —no hay CHECK que lo impida— y sin
             * este filtro el while no termina nunca.
             */
            $siguiente = array_values(array_diff($siguiente, $ids));

            $ids = [...$ids, ...$siguiente];
            $nivel = $siguiente;
        }

        return $ids;
    }

    /**
     * @return Collection<int, Unidad>
     */
    public function descendientes(): Collection
    {
        $ids = array_slice($this->idsConDescendientes(), 1);

        /** @var Collection<int, Unidad> */
        return static::query()->whereIn('id', $ids)->get();
    }
}
