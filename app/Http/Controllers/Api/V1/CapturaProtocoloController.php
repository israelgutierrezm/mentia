<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluaciones\Servicios\CapturaProtocolo;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Doc 07 §5 — captura de protocolo para instrumentos CAP.
 *
 * El examinador registra PUNTAJES, no respuestas: para un WISC o un ADOS-2 la
 * editorial prohíbe la aplicación en línea, así que lo que entra es el
 * resultado del protocolo de papel (Doc 01 §6).
 *
 * A diferencia del resto del motor, esto SÍ va detrás de sanctum: lo hace un
 * profesional identificado, no quien contesta con una liga.
 */
class CapturaProtocoloController extends ApiV1Controller
{
    public function __construct(private readonly CapturaProtocolo $captura) {}

    public function store(Request $peticion): JsonResponse
    {
        $validado = $peticion->validate([
            'persona_uuid' => ['required', 'uuid'],
            'version_instrumento_id' => ['required', 'integer'],
            'fecha_aplicacion' => ['required', 'date', 'before_or_equal:today'],
            'escalas' => ['required', 'array', 'min:1'],
            'escalas.*.clave' => ['required', 'string', 'max:40'],
            'escalas.*.puntaje_bruto' => ['required', 'numeric'],
            'escalas.*.puntaje_escalar' => ['nullable', 'numeric'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ]);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        try {
            $aplicacion = $this->captura->registrar(
                personaUuid: $validado['persona_uuid'],
                versionInstrumentoId: $validado['version_instrumento_id'],
                fechaAplicacion: $validado['fecha_aplicacion'],
                escalas: $validado['escalas'],
                capturadoPor: $usuario->persona,
                observaciones: $validado['observaciones'] ?? null,
            );
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['escalas' => $error->getMessage()]);
        }

        return response()->json([
            'aplicacion_uuid' => $aplicacion->uuid,
            'estado' => $aplicacion->estado,
            'aviso' => 'La calificación quedó encolada desde la etapa de normalización.',
        ], 202);
    }
}
