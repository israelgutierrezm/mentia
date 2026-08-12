<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\CatalogoPermisos;
use App\Domain\Accesos\Excepciones\RolNoModificable;
use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Modelos\RolSensibilidadMax;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * La organización define sus propios roles: qué permisos lleva cada uno y
 * hasta qué nivel de sensibilidad alcanza.
 *
 * Los PERMISOS no se crean desde pantalla —son llaves que el código consulta y
 * viven en CatalogoPermisos—; lo que se compone aquí es qué llaves lleva cada
 * rol. Los roles clonados de las plantillas al crear el tenant son de ejemplo y
 * se pueden editar o borrar: ningún código debe buscarlos por nombre.
 */
class GestorRoles
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  list<string>  $permisos
     */
    public function crear(string $nombre, array $permisos, int $nivelMaximo): Rol
    {
        $organizacionId = $this->organizacionActiva();
        $this->exigirPermisosDelCatalogo($permisos);

        return DB::transaction(function () use ($nombre, $permisos, $nivelMaximo, $organizacionId): Rol {
            /** @var Rol $rol */
            $rol = Rol::query()->create([
                'name' => $nombre,
                'guard_name' => 'web',
                'organizacion_id' => $organizacionId,
            ]);

            $rol->syncPermissions($permisos);

            RolSensibilidadMax::query()->create([
                'rol_id' => $rol->id,
                'nivel_sensibilidad_max' => $nivelMaximo,
            ]);

            $this->registrar->forgetCachedPermissions();

            return $rol;
        });
    }

    /**
     * @param  list<string>  $permisos
     *
     * @throws RolNoModificable
     */
    public function actualizar(
        Rol $rol,
        string $nombre,
        array $permisos,
        int $nivelMaximo,
        ?Persona $actor = null,
    ): Rol {
        $this->exigirQueSeaDeEsteTenant($rol);
        $this->exigirPermisosDelCatalogo($permisos);
        $this->exigirQueNoSeAutoEncierre($rol, $permisos, $actor);

        return DB::transaction(function () use ($rol, $nombre, $permisos, $nivelMaximo): Rol {
            $rol->update(['name' => $nombre]);
            $rol->syncPermissions($permisos);

            RolSensibilidadMax::query()->updateOrCreate(
                ['rol_id' => $rol->id],
                ['nivel_sensibilidad_max' => $nivelMaximo]
            );

            $this->registrar->forgetCachedPermissions();

            return $rol;
        });
    }

    /**
     * @throws RolNoModificable
     */
    public function eliminar(Rol $rol): void
    {
        $this->exigirQueSeaDeEsteTenant($rol);

        /*
         * `persona_rol_alcances.rol_id` tiene FK en cascada, así que borrar el
         * rol se llevaría en silencio todos sus alcances —y con ellos el
         * registro de quién tenía acceso a qué—. Se bloquea y se pide retirar
         * los alcances primero, que es una acción visible y auditable.
         */
        $alcances = PersonaRolAlcance::query()
            ->withoutGlobalScopes()
            ->where('rol_id', $rol->id)
            ->count();

        if ($alcances > 0) {
            throw RolNoModificable::porTenerAlcancesVivos($alcances);
        }

        DB::transaction(function () use ($rol): void {
            RolSensibilidadMax::query()->where('rol_id', $rol->id)->delete();
            $rol->delete();

            $this->registrar->forgetCachedPermissions();
        });
    }

    /**
     * Los roles de la organización activa, con sus permisos y su tope.
     *
     * @return \Illuminate\Support\Collection<int, Rol>
     */
    public function listar(): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, Rol> */
        return Rol::query()
            ->where('organizacion_id', $this->organizacionActiva())
            ->with(['permissions', 'topeSensibilidad'])
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    private function organizacionActiva(): int
    {
        $id = $this->contexto->id();

        if ($id === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        return $id;
    }

    private function exigirQueSeaDeEsteTenant(Rol $rol): void
    {
        if ($rol->organizacion_id !== $this->organizacionActiva()) {
            // 404 y no 403: confirmar que el rol existe le diría a quien
            // pregunta cómo está organizado otro tenant.
            abort(404);
        }
    }

    /**
     * @param  list<string>  $permisos
     *
     * @throws RolNoModificable
     */
    private function exigirPermisosDelCatalogo(array $permisos): void
    {
        foreach ($permisos as $permiso) {
            if (! CatalogoPermisos::existe($permiso)) {
                throw RolNoModificable::porPermisoDesconocido($permiso);
            }
        }
    }

    /**
     * Salvaguarda contra dejarse fuera.
     *
     * Si quien edita tiene ESTE rol y le está quitando `roles.gestionar`, se
     * queda sin forma de volver a esta pantalla —y si es el único rol que lo
     * tiene, la organización entera pierde la administración de roles y sólo
     * se repara desde la consola—.
     *
     * @param  list<string>  $permisos
     *
     * @throws RolNoModificable
     */
    private function exigirQueNoSeAutoEncierre(Rol $rol, array $permisos, ?Persona $actor): void
    {
        if ($actor === null) {
            return;
        }

        if (in_array('roles.gestionar', $permisos, true)) {
            return;
        }

        $loTiene = $actor->roles()
            ->where('roles.id', $rol->id)
            ->exists();

        if ($loTiene) {
            throw RolNoModificable::porAutoEncierro();
        }
    }
}
