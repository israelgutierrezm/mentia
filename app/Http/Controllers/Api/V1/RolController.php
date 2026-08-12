<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accesos\CatalogoPermisos;
use App\Domain\Accesos\Datos\Permiso;
use App\Domain\Accesos\Excepciones\RolNoModificable;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Servicios\GestorRoles;
use App\Http\Requests\GuardaRolRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Espejo de Web\RolController: MISMO GestorRoles, otra salida.
 */
class RolController extends ApiV1Controller
{
    public function __construct(private readonly GestorRoles $gestor) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->gestor->listar()->map(fn (Rol $rol): array => [
                'id' => $rol->id,
                'nombre' => $rol->name,
                'permisos' => $rol->permissions->pluck('name')->all(),
                'nivel_sensibilidad_max' => $rol->nivelSensibilidadMaximo(),
                'personas' => $rol->users_count ?? 0,
            ])->all(),
        ]);
    }

    /**
     * El catálogo de permisos del sistema. Lo consume la app y cualquier
     * cliente que arme una pantalla de roles: sin él tendría que cablear las
     * llaves, y se quedaría viejo en cuanto se agregue un permiso.
     */
    public function catalogo(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (Permiso $permiso): array => [
                    'clave' => $permiso->clave,
                    'dominio' => $permiso->dominio,
                    'etiqueta' => $permiso->etiqueta,
                    'descripcion' => $permiso->descripcion,
                ],
                CatalogoPermisos::todos()
            ),
        ]);
    }

    public function store(GuardaRolRequest $peticion): JsonResponse
    {
        $validado = $peticion->validated();

        try {
            $rol = $this->gestor->crear(
                $validado['nombre'],
                $validado['permisos'],
                (int) $validado['nivel_sensibilidad_max']
            );
        } catch (RolNoModificable $error) {
            throw ValidationException::withMessages(['permisos' => $error->getMessage()]);
        }

        return response()->json(['id' => $rol->id, 'nombre' => $rol->name], 201);
    }

    public function update(GuardaRolRequest $peticion, Rol $rol): JsonResponse
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
            throw ValidationException::withMessages(['permisos' => $error->getMessage()]);
        }

        return response()->json(['id' => $rol->id, 'nombre' => $rol->name]);
    }

    public function destroy(Rol $rol): JsonResponse
    {
        try {
            $this->gestor->eliminar($rol);
        } catch (RolNoModificable $error) {
            throw ValidationException::withMessages(['rol' => $error->getMessage()]);
        }

        return response()->json(status: 204);
    }
}
