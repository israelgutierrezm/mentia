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
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
