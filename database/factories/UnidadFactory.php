<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidad>
 */
class UnidadFactory extends Factory
{
    protected $model = Unidad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizacion_id' => Organizacion::factory(),
            'unidad_padre_id' => null,
            'nombre' => 'Plantel '.$this->faker->citySuffix(),
            'tipo' => 'plantel',
            'estado' => 'activa',
        ];
    }

    public function hijaDe(Unidad $padre): self
    {
        return $this->state(fn (): array => [
            'organizacion_id' => $padre->organizacion_id,
            'unidad_padre_id' => $padre->id,
            'tipo' => 'departamento',
        ]);
    }
}
