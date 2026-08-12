<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Organizaciones\Modelos\TipoAgrupacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agrupacion>
 */
class AgrupacionFactory extends Factory
{
    protected $model = Agrupacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizacion_id' => Organizacion::factory(),
            'unidad_id' => null,
            'tipo_agrupacion_id' => fn (): int => TipoAgrupacion::query()->firstOrCreate(
                ['organizacion_id' => null, 'clave' => 'grupo_escolar'],
                ['nombre' => 'Grupo escolar']
            )->id,
            'nombre' => $this->faker->numberBetween(1, 6).'° '.$this->faker->randomLetter(),
            'periodo_inicio' => null,
            'periodo_fin' => null,
            'estado' => 'activa',
        ];
    }

    public function enUnidad(Unidad $unidad): self
    {
        return $this->state(fn (): array => [
            'organizacion_id' => $unidad->organizacion_id,
            'unidad_id' => $unidad->id,
        ]);
    }
}
