<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Organizaciones\Modelos\TipoOrganizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organizacion>
 */
class OrganizacionFactory extends Factory
{
    protected $model = Organizacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => $this->faker->company(),
            'tipo_organizacion_id' => fn (): int => TipoOrganizacion::query()
                ->firstOrCreate(
                    ['clave' => 'escuela'],
                    [
                        'nombre' => 'Escuela',
                        'vocabulario_persona' => 'alumno',
                        'vocabulario_agrupacion' => 'grupo',
                    ]
                )->id,
            'rfc' => null,
            'estado' => 'activa',
            'zona_horaria' => 'America/Mexico_City',
        ];
    }

    public function suspendida(): self
    {
        return $this->state(fn (): array => ['estado' => 'suspendida']);
    }
}
