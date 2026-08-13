<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Personas\Modelos\Persona;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * La CUENTA. La identidad es Persona.
 *
 * Se separan porque la mayoría de las personas del sistema nunca tendrán
 * cuenta —un niño de preescolar tamizado por M-CHAT existe en el expediente y
 * no inicia sesión— y porque los ROLES cuelgan de la persona, no de aquí
 * (Doc 03 §M3). Un usuario sin persona no existe: `persona_id` es NOT NULL.
 *
 * Queda en App\Models y no en app/Domain/ a propósito: `users` es la tabla de
 * autenticación de Laravel y varios paquetes la esperan ahí.
 *
 * @property int $id
 * @property int $persona_id
 * @property string|null $dos_factores_secreto
 * @property \Illuminate\Support\Carbon|null $dos_factores_confirmado_en
 * @property string|null $dos_factores_recuperacion
 * @property string $name
 * @property string $email
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'persona_id',
        'name',
        'email',
        'password',
        'dos_factores_secreto',
        'dos_factores_confirmado_en',
        'dos_factores_recuperacion',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',

        /*
         * El secreto de 2FA y los códigos de recuperación NUNCA se serializan.
         * Ni en un API Resource por descuido, ni en un `dd()` durante una
         * sesión de soporte: quien los vea puede entrar como esa persona.
         */
        'dos_factores_secreto',
        'dos_factores_recuperacion',
    ];

    /**
     * Valores por omisión de las columnas de 2FA.
     *
     * Hacen falta porque el modo estricto de Eloquent
     * (`preventAccessingMissingAttributes`) revienta al leer una columna que la
     * instancia no trae, y un `create()` sólo trae lo que insertó. Sin esto,
     * cualquier código que pregunte por el segundo factor de un usuario recién
     * creado tumba la petición con un error que no dice nada.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dos_factores_secreto' => null,
        'dos_factores_confirmado_en' => null,
        'dos_factores_recuperacion' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dos_factores_confirmado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
