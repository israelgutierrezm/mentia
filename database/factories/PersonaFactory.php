<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Personas\Modelos\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),

            /*
             * CURP sintética con el formato oficial pero SIN pretender ser
             * válida: 18 caracteres, letras y dígitos donde van. Generar CURPs
             * que pasen el algoritmo real produciría, tarde o temprano, la de
             * una persona existente en una base de pruebas.
             */
            'curp' => strtoupper(
                $this->faker->lexify('????')
                .$this->faker->numerify('######')
                .$this->faker->randomElement(['H', 'M'])
                .$this->faker->lexify('?????')
                .$this->faker->bothify('?#')
            ),

            'nombres' => $this->faker->firstName(),
            'primer_apellido' => $this->faker->lastName(),
            'segundo_apellido' => $this->faker->lastName(),
            'fecha_nacimiento' => $this->faker->dateTimeBetween('-60 years', '-18 years'),
            'sexo_registral' => $this->faker->randomElement(['M', 'F', 'X']),
            'verificacion_identidad' => 'no_verificada',
        ];
    }

    public function menor(int $edad = 10): self
    {
        return $this->state(fn (): array => [
            'fecha_nacimiento' => now()->subYears($edad)->subMonths(3),
        ]);
    }

    public function sinCurp(): self
    {
        return $this->state(fn (): array => ['curp' => null]);
    }
}
