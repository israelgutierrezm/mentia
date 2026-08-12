<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Una cuenta sin persona no existe: persona_id es NOT NULL.
            'persona_id' => Persona::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function de(Persona $persona): static
    {
        return $this->state(fn (): array => [
            'persona_id' => $persona->id,
            'name' => $persona->nombreCompleto(),
        ]);
    }
}
