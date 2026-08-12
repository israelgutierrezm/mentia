<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Modelos\RolSensibilidadMax;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Arma organizaciones completas para las pruebas.
 *
 * Existe porque montar un caso de AccesoService a mano son ocho inserciones
 * —organización, unidad, agrupación, dos personas, vínculo, rol con permisos y
 * tope, alcance con vigencia— y cuando eso se repite en cada prueba, las
 * pruebas empiezan a diferir en detalles que nadie quiso cambiar.
 */
class EscenarioTenant
{
    public Organizacion $organizacion;

    public function __construct(?Organizacion $organizacion = null)
    {
        $this->organizacion = $organizacion ?? Organizacion::factory()->create();
    }

    public static function nuevo(): self
    {
        return new self;
    }

    /**
     * Fija esta organización como la activa.
     */
    public function activar(): self
    {
        app(ContextoOrganizacion::class)->establecer($this->organizacion);

        return $this;
    }

    public function unidad(?Unidad $padre = null, string $nombre = 'Unidad'): Unidad
    {
        $factory = Unidad::factory()->state([
            'organizacion_id' => $this->organizacion->id,
            'nombre' => $nombre,
        ]);

        if ($padre !== null) {
            $factory = $factory->state([
                'unidad_padre_id' => $padre->id,
                'tipo' => 'departamento',
            ]);
        }

        return $factory->create();
    }

    public function agrupacion(?Unidad $unidad = null, string $nombre = 'Grupo'): Agrupacion
    {
        return Agrupacion::factory()->create([
            'organizacion_id' => $this->organizacion->id,
            'unidad_id' => $unidad?->id,
            'nombre' => $nombre,
        ]);
    }

    /**
     * Una persona vinculada a esta organización.
     */
    public function persona(bool $vincular = true): Persona
    {
        $persona = Persona::factory()->create();

        if ($vincular) {
            OrganizacionPersona::query()->create([
                'organizacion_id' => $this->organizacion->id,
                'persona_id' => $persona->id,
                'estado' => 'activa',
                'origen_alta' => 'creada',
                'fecha_alta' => Carbon::now()->toDateString(),
            ]);
        }

        return $persona;
    }

    public function usuarioDe(Persona $persona): User
    {
        return User::factory()->de($persona)->create();
    }

    /**
     * Un rol de ESTA organización con sus permisos y su tope de sensibilidad.
     *
     * @param  list<string>  $permisos
     */
    public function rol(string $nombre, array $permisos = [], int $nivelMaximo = 1): Rol
    {
        /** @var Rol $rol */
        $rol = Rol::query()->create([
            'name' => $nombre,
            'guard_name' => 'web',
            'organizacion_id' => $this->organizacion->id,
        ]);

        $rol->syncPermissions($permisos);

        RolSensibilidadMax::query()->create([
            'rol_id' => $rol->id,
            'nivel_sensibilidad_max' => $nivelMaximo,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $rol;
    }

    /**
     * Asigna el rol a la persona EN ESTA organización y le da un alcance.
     */
    public function asignarRol(
        Persona $persona,
        Rol $rol,
        string $alcanceTipo = PersonaRolAlcance::TIPO_ORGANIZACION,
        ?int $alcanceId = null,
        ?string $vigenciaFin = null,
        ?string $vigenciaInicio = null,
    ): PersonaRolAlcance {
        $registrar = app(PermissionRegistrar::class);

        /*
         * El team id de Spatie tiene que apuntar a esta organización al
         * asignar: `assignRole` escribe `organizacion_id` en model_has_roles
         * con lo que haya en el registrar, no con el del rol. Sin esto, en una
         * prueba con dos tenants el rol se asigna al que quedó activo de
         * último y la prueba pasa por la razón equivocada.
         */
        $anterior = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->organizacion->id);

        try {
            $persona->assignRole($rol);
        } finally {
            $registrar->setPermissionsTeamId($anterior);
        }

        return PersonaRolAlcance::query()->create([
            'organizacion_id' => $this->organizacion->id,
            'persona_id' => $persona->id,
            'rol_id' => $rol->id,
            'alcance_tipo' => $alcanceTipo,
            'alcance_id' => $alcanceId ?? $this->organizacion->id,
            'vigencia_inicio' => $vigenciaInicio ?? Carbon::now()->subYear()->toDateString(),
            'vigencia_fin' => $vigenciaFin,
        ]);
    }

    public function inscribir(
        Persona $persona,
        Agrupacion $agrupacion,
        ?string $fechaBaja = null,
    ): AgrupacionMiembro {
        return AgrupacionMiembro::query()->create([
            'agrupacion_id' => $agrupacion->id,
            'persona_id' => $persona->id,
            'rol_en_agrupacion' => 'evaluado',
            'fecha_alta' => Carbon::now()->subMonths(6)->toDateString(),
            'fecha_baja' => $fechaBaja,
        ]);
    }

    public function tutoria(
        Persona $tutor,
        Persona $menor,
        string $estado = 'vigente',
    ): Tutoria {
        return Tutoria::query()->create([
            'tutor_persona_id' => $tutor->id,
            'menor_persona_id' => $menor->id,
            'parentesco' => 'madre',
            'estado' => $estado,
            'vigencia_inicio' => Carbon::now()->subYear()->toDateString(),
        ]);
    }
}
