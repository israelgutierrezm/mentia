<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Personas\Excepciones\TutoriaInvalida;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Domain\Personas\Servicios\GestorTutorias;
use App\Http\Requests\GuardaTutoriaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Espejo de Web\TutoriaController: MISMO GestorTutorias.
 *
 * Doc 07 §2: `GET/POST /tutorias` · `POST /tutorias/{id}/validar`.
 */
class TutoriaController extends ApiV1Controller
{
    public function __construct(private readonly GestorTutorias $gestor) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->gestor->listar()->map(fn (Tutoria $tutoria): array => [
                'id' => $tutoria->id,
                'tutor_uuid' => $tutoria->tutor->uuid,
                'menor_uuid' => $tutoria->menor->uuid,
                'parentesco' => $tutoria->parentesco,
                'estado' => $tutoria->estado,
                'vigencia_inicio' => $tutoria->vigencia_inicio->toDateString(),
                'vigencia_fin' => $tutoria->vigencia_fin?->toDateString(),
                'vigente' => $tutoria->estaVigente(),
            ])->all(),
        ]);
    }

    public function store(GuardaTutoriaRequest $peticion): JsonResponse
    {
        $validado = $peticion->validated();

        try {
            $tutoria = $this->gestor->registrar(
                $this->personaPorUuid($validado['tutor_uuid']),
                $this->personaPorUuid($validado['menor_uuid']),
                $validado['parentesco'],
                $validado['vigencia_inicio'] ?? null,
                $validado['vigencia_fin'] ?? null,
            );
        } catch (TutoriaInvalida $error) {
            throw ValidationException::withMessages(['tutor_uuid' => $error->getMessage()]);
        }

        return response()->json([
            'id' => $tutoria->id,
            'estado' => $tutoria->estado,
            'aviso' => 'Pendiente de validación: todavía no da acceso.',
        ], 201);
    }

    public function validar(Request $peticion, Tutoria $tutoria): JsonResponse
    {
        $validador = $peticion->user()?->persona;
        abort_if($validador === null, 403);

        try {
            $this->gestor->validar($tutoria, $validador);
        } catch (TutoriaInvalida $error) {
            throw ValidationException::withMessages(['tutoria' => $error->getMessage()]);
        }

        return response()->json(['id' => $tutoria->id, 'estado' => $tutoria->estado]);
    }

    public function revocar(Tutoria $tutoria): JsonResponse
    {
        try {
            $this->gestor->revocar($tutoria);
        } catch (TutoriaInvalida $error) {
            throw ValidationException::withMessages(['tutoria' => $error->getMessage()]);
        }

        return response()->json(['id' => $tutoria->id, 'estado' => $tutoria->estado]);
    }

    private function personaPorUuid(string $uuid): Persona
    {
        return Persona::query()
            ->where('uuid', $uuid)
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->firstOrFail();
    }
}
