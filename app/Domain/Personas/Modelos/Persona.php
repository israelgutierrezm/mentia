<?php

declare(strict_types=1);

namespace App\Domain\Personas\Modelos;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Models\User;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * La entidad raíz del sistema. GLOBAL: no pertenece a ningún tenant.
 *
 * Los ROLES cuelgan de aquí y no de `users` (Doc 03 §M3, Doc 06 §1): quien
 * tiene un permiso es la persona, y la misma persona puede tener roles
 * distintos en organizaciones distintas —Spatie en modo teams lo resuelve con
 * `organizacion_id`—. Colgarlos de la cuenta habría dejado sin roles a todas
 * las personas que no tienen sesión, que son la mayoría.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $curp
 * @property string $nombres
 * @property string $primer_apellido
 * @property string|null $segundo_apellido
 * @property Carbon $fecha_nacimiento
 * @property string $sexo_registral
 * @property string $verificacion_identidad
 */
class Persona extends Modelo
{
    use HasRoles;

    protected $table = 'personas';

    protected $fillable = [
        'uuid',
        'curp',
        'nombres',
        'primer_apellido',
        'segundo_apellido',
        'fecha_nacimiento',
        'sexo_registral',
        'verificacion_identidad',
    ];

    /**
     * El guard de Spatie. Los roles se resuelven contra `web` aunque la
     * petición venga por API: el permiso es de la persona, no del canal por el
     * que entró.
     */
    protected string $guard_name = 'web';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $persona): void {
            if ($persona->uuid === null) {
                $persona->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        // Hacia afuera viaja el uuid, nunca el id: un id se cuenta, y quien
        // pidiera 1, 2, 3… se llevaría el padrón completo.
        return 'uuid';
    }

    public function nombreCompleto(): string
    {
        return trim(sprintf(
            '%s %s %s',
            $this->nombres,
            $this->primer_apellido,
            $this->segundo_apellido ?? ''
        ));
    }

    public function edadEnMeses(?Carbon $al = null): int
    {
        return (int) $this->fecha_nacimiento->diffInMonths($al ?? Carbon::now());
    }

    public function esMenorDeEdad(?Carbon $al = null): bool
    {
        return $this->fecha_nacimiento->diffInYears($al ?? Carbon::now()) < 18;
    }

    /**
     * @return HasOne<User, $this>
     */
    public function usuario(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return HasMany<OrganizacionPersona, $this>
     */
    public function vinculaciones(): HasMany
    {
        return $this->hasMany(OrganizacionPersona::class);
    }

    /**
     * Tutelas donde esta persona es el TUTOR.
     *
     * @return HasMany<Tutoria, $this>
     */
    public function tutelas(): HasMany
    {
        return $this->hasMany(Tutoria::class, 'tutor_persona_id');
    }

    /**
     * Tutelas donde esta persona es el MENOR.
     *
     * @return HasMany<Tutoria, $this>
     */
    public function tutores(): HasMany
    {
        return $this->hasMany(Tutoria::class, 'menor_persona_id');
    }

    /**
     * @return BelongsToMany<Agrupacion, $this>
     */
    public function agrupaciones(): BelongsToMany
    {
        return $this->belongsToMany(Agrupacion::class, 'agrupacion_miembros')
            ->withPivot(['rol_en_agrupacion', 'fecha_alta', 'fecha_baja']);
    }

    /**
     * @return HasMany<PersonaRolAlcance, $this>
     */
    public function alcances(): HasMany
    {
        return $this->hasMany(PersonaRolAlcance::class);
    }
}
