<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\CatalogoPermisos;
use App\Domain\Accesos\Modelos\PlantillaRol;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Modelos\RolSensibilidadMax;
use App\Domain\Organizaciones\Modelos\Organizacion;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Clona las plantillas de rol a roles propios de una organización.
 *
 * Se CLONA, no se apunta. Si los roles del tenant apuntaran a la plantilla
 * global, corregir una plantilla cambiaría los permisos efectivos de todos los
 * tenants en producción sin que ninguno lo pidiera — y en un sistema donde un
 * permiso decide quién ve un resultado clínico, eso no puede pasar por un
 * despliegue.
 */
class ClonadorPlantillasRol
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return Collection<int, Rol>
     */
    public function clonarPara(Organizacion $organizacion): Collection
    {
        $plantillas = PlantillaRol::query()
            ->paraTipo($organizacion->tipo_organizacion_id)
            ->with('permisos')
            ->get();

        /** @var Collection<int, Rol> $roles */
        $roles = new Collection;

        foreach ($plantillas as $plantilla) {
            $roles->push($this->clonar($plantilla, $organizacion));
        }

        $this->registrar->forgetCachedPermissions();

        return $roles;
    }

    private function clonar(PlantillaRol $plantilla, Organizacion $organizacion): Rol
    {
        /** @var Rol $rol */
        $rol = Rol::query()->firstOrCreate([
            'name' => $plantilla->nombre,
            'guard_name' => 'web',
            'organizacion_id' => $organizacion->id,
        ]);

        /*
         * Se filtran contra el catálogo antes de asignar.
         *
         * Una plantilla sembrada con un permiso que ya se retiró del código
         * reventaría aquí con PermissionDoesNotExist y dejaría el alta del
         * tenant a medias. Preferimos que el tenant nazca con un permiso de
         * menos —visible y corregible desde la pantalla de roles— a que no
         * nazca.
         */
        $permisos = $plantilla->permisos
            ->pluck('permiso')
            ->filter(static fn (string $clave): bool => CatalogoPermisos::existe($clave))
            ->values()
            ->all();

        $rol->syncPermissions($permisos);

        RolSensibilidadMax::query()->updateOrCreate(
            ['rol_id' => $rol->id],
            ['nivel_sensibilidad_max' => $plantilla->nivel_sensibilidad_max]
        );

        return $rol;
    }
}
