<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\CatalogoPermisos;
use App\Domain\Accesos\Datos\Permiso;
use App\Domain\Accesos\Excepciones\RolNoModificable;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Servicios\GestorRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaRolRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RolController extends Controller
{
    public function __construct(private readonly GestorRoles $gestor) {}

    public function index(): Response
    {
        return Inertia::render('Accesos/Roles', [
            'roles' => $this->gestor->listar()->map(fn (Rol $rol): array => [
                'id' => $rol->id,
                'nombre' => $rol->name,
                'permisos' => $rol->permissions->pluck('name')->all(),
                'nivel_sensibilidad_max' => $rol->nivelSensibilidadMaximo(),
                'personas' => $rol->users_count ?? 0,
            ])->all(),

            /*
             * El catálogo va agrupado por dominio y CON etiqueta legible: una
             * lista de llaves técnicas (`resultados.ver_detalle`) hace que
             * quien configura un rol termine concediendo cosas que no entendió.
             */
            'catalogo' => collect(CatalogoPermisos::todos())
                ->groupBy(fn (Permiso $permiso): string => $permiso->dominio)
                ->map(fn ($permisos) => $permisos->map(fn (Permiso $permiso): array => [
                    'clave' => $permiso->clave,
                    'etiqueta' => $permiso->etiqueta,
                    'descripcion' => $permiso->descripcion,
                ])->values())
                ->all(),
        ]);
    }

    public function store(GuardaRolRequest $peticion): RedirectResponse
    {
        $validado = $peticion->validated();

        try {
            $this->gestor->crear(
                $validado['nombre'],
                $validado['permisos'],
                (int) $validado['nivel_sensibilidad_max']
            );
        } catch (RolNoModificable $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Rol creado.');
    }

    public function update(GuardaRolRequest $peticion, Rol $rol): RedirectResponse
    {
        $validado = $peticion->validated();

        try {
            $this->gestor->actualizar(
                $rol,
                $validado['nombre'],
                $validado['permisos'],
                (int) $validado['nivel_sensibilidad_max'],
                $peticion->user()?->persona
            );
        } catch (RolNoModificable $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Rol actualizado.');
    }

    public function destroy(Request $peticion, Rol $rol): RedirectResponse
    {
        abort_unless($peticion->user()?->can('roles.gestionar') ?? false, 403);

        try {
            $this->gestor->eliminar($rol);
        } catch (RolNoModificable $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Rol eliminado.');
    }
}
