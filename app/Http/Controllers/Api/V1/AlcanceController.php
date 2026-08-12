<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Servicios\AsignadorRoles;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Requests\GuardaAlcanceRequest;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlcanceController extends ApiV1Controller
{
    public function __construct(
        private readonly AsignadorRoles $asignador,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $alcances = PersonaRolAlcance::query()
            ->with(['persona', 'rol'])
            ->orderBy('id')
            ->cursorPaginate($this->limite((int) $peticion->query('limit', '0')));

        return response()->json([
            'data' => $alcances->getCollection()
                ->map(fn (PersonaRolAlcance $alcance): array => [
                    'id' => $alcance->id,
                    'persona_uuid' => $alcance->persona->uuid,
                    'rol' => $alcance->rol?->name,
                    'alcance_tipo' => $alcance->alcance_tipo,
                    'alcance_id' => $alcance->alcance_id,
                    'vigencia_inicio' => $alcance->vigencia_inicio->toDateString(),
                    'vigencia_fin' => $alcance->vigencia_fin?->toDateString(),
                    'vigente' => $alcance->estaVigente(),
                ])->all(),
            'cursor' => $alcances->nextCursor()?->encode(),
        ]);
    }

    public function store(GuardaAlcanceRequest $peticion): JsonResponse
    {
        $validado = $peticion->validated();

        $persona = Persona::query()->where('uuid', $validado['persona_uuid'])->firstOrFail();

        $rol = Rol::query()
            ->where('organizacion_id', $this->contexto->id())
            ->findOrFail($validado['rol_id']);

        $alcance = $this->asignador->asignar(
            persona: $persona,
            rol: $rol,
            alcanceTipo: $validado['alcance_tipo'],
            alcanceId: isset($validado['alcance_id']) ? (int) $validado['alcance_id'] : null,
            vigenciaInicio: $validado['vigencia_inicio'] ?? null,
            vigenciaFin: $validado['vigencia_fin'] ?? null,
            otorgadoPor: $peticion->user()?->persona,
        );

        return response()->json(['id' => $alcance->id], 201);
    }

    public function destroy(PersonaRolAlcance $alcance): JsonResponse
    {
        $this->asignador->retirar($alcance);

        return response()->json(status: 204);
    }
}
