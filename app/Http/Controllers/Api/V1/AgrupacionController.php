<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Servicios\GestorAgrupaciones;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Requests\GuardaAgrupacionRequest;
use App\Http\Resources\AgrupacionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgrupacionController extends ApiV1Controller
{
    public function __construct(private readonly GestorAgrupaciones $gestor) {}

    public function index(Request $peticion): AnonymousResourceCollection
    {
        $agrupaciones = Agrupacion::query()
            ->withCount('miembrosVigentes')
            ->orderBy('id')
            ->cursorPaginate($this->limite((int) $peticion->query('limit', '0')));

        return AgrupacionResource::collection($agrupaciones);
    }

    public function store(GuardaAgrupacionRequest $peticion): JsonResponse
    {
        $agrupacion = $this->gestor->crear($peticion->validated());

        return (new AgrupacionResource($agrupacion))->response()->setStatusCode(201);
    }

    public function update(
        GuardaAgrupacionRequest $peticion,
        Agrupacion $agrupacion,
    ): AgrupacionResource {
        return new AgrupacionResource($this->gestor->actualizar($agrupacion, $peticion->validated()));
    }

    public function inscribir(Request $peticion, Agrupacion $agrupacion): JsonResponse
    {
        $peticion->validate([
            'persona_uuid' => ['required', 'uuid'],
            'rol_en_agrupacion' => ['sometimes', 'in:evaluado,titular_responsable'],
        ]);

        // Igual que en web: la persona tiene que estar vinculada a la
        // organización activa. `personas` es global y no tiene global scope.
        $persona = Persona::query()
            ->where('uuid', $peticion->string('persona_uuid')->toString())
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->firstOrFail();

        $miembro = $this->gestor->inscribir(
            $agrupacion,
            $persona,
            $peticion->string('rol_en_agrupacion', 'evaluado')->toString()
        );

        return response()->json([
            'id' => $miembro->id,
            'persona_uuid' => $persona->uuid,
            'rol_en_agrupacion' => $miembro->rol_en_agrupacion,
            'fecha_alta' => $miembro->fecha_alta->toDateString(),
        ], 201);
    }

    public function darDeBaja(Agrupacion $agrupacion, AgrupacionMiembro $miembro): JsonResponse
    {
        abort_unless($miembro->agrupacion_id === $agrupacion->id, 404);

        $this->gestor->darDeBaja($miembro);

        return response()->json([
            'id' => $miembro->id,
            'fecha_baja' => $miembro->fecha_baja?->toDateString(),
        ]);
    }
}
