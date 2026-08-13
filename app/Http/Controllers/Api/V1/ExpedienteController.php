<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expedientes\Excepciones\CapturaNoPermitida;
use App\Domain\Expedientes\Modelos\ExpedienteCampo;
use App\Domain\Expedientes\Servicios\CapturaExpediente;
use App\Domain\Expedientes\Servicios\VistaExpediente;
use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §2: GET /expedientes/{persona_uuid} · PUT .../valores
 */
class ExpedienteController extends ApiV1Controller
{
    public function __construct(
        private readonly VistaExpediente $vista,
        private readonly CapturaExpediente $captura,
    ) {}

    public function show(Request $peticion, Persona $persona): JsonResponse
    {
        return response()->json([
            'persona_uuid' => $persona->uuid,
            'secciones' => $this->vista->paraActor($persona, $this->actor($peticion)),
        ]);
    }

    public function guardarValores(Request $peticion, Persona $persona): JsonResponse
    {
        $validado = $peticion->validate([
            'valores' => ['required', 'array', 'min:1'],
            'valores.*.campo_id' => ['required', 'integer'],
            'valores.*.valor' => ['nullable'],
        ]);

        $actor = $this->actor($peticion);
        $guardados = [];

        foreach ($validado['valores'] as $entrada) {
            $campo = ExpedienteCampo::query()->findOrFail($entrada['campo_id']);

            try {
                $valor = $this->captura->capturar(
                    $persona,
                    $campo,
                    $entrada['valor'] ?? null,
                    $actor
                );
            } catch (CapturaNoPermitida $error) {
                throw ValidationException::withMessages([
                    'valores' => $error->getMessage(),
                ]);
            }

            $guardados[] = [
                'campo_id' => $campo->id,
                'version' => $valor->version,
                'estado' => $valor->estado,
            ];
        }

        return response()->json(['data' => $guardados]);
    }

    public function pendientes(Request $peticion, Persona $persona): JsonResponse
    {
        $expediente = $this->captura->expedienteDe($persona);

        return response()->json([
            'data' => $this->captura->pendientesDeValidar($expediente)
                ->map(fn ($valor): array => [
                    'id' => $valor->id,
                    'campo' => $valor->campo->etiqueta,
                    'valor' => $valor->contenido(),
                    'version' => $valor->version,
                ])->values()->all(),
        ]);
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();

        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
