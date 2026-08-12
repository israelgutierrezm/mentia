<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Organizaciones\Servicios\GestorUnidades;
use App\Http\Requests\GuardaUnidadRequest;
use App\Http\Resources\UnidadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Espejo de Web\UnidadController: MISMO servicio de dominio, otra salida.
 */
class UnidadController extends ApiV1Controller
{
    public function __construct(private readonly GestorUnidades $gestor) {}

    public function index(Request $peticion): AnonymousResourceCollection
    {
        $unidades = Unidad::query()
            ->orderBy('id')
            ->cursorPaginate($this->limite((int) $peticion->query('limit', '0')));

        return UnidadResource::collection($unidades);
    }

    public function store(GuardaUnidadRequest $peticion): JsonResponse
    {
        $unidad = $this->gestor->crear($peticion->validated());

        return (new UnidadResource($unidad))->response()->setStatusCode(201);
    }

    public function update(GuardaUnidadRequest $peticion, Unidad $unidad): UnidadResource
    {
        return new UnidadResource($this->gestor->actualizar($unidad, $peticion->validated()));
    }
}
