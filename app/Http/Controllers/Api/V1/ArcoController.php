<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Consentimientos\Modelos\SolicitudArco;
use App\Domain\Consentimientos\Servicios\GestorArco;
use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Derechos ARCO (Doc 06 §3).
 *
 * El listado ordena por PLAZO, no por fecha de recepción: lo que importa no es
 * cuál llegó primero sino cuál vence antes, y una solicitud que se pasa de
 * plazo es un incumplimiento con sanción.
 */
class ArcoController extends ApiV1Controller
{
    public function __construct(
        private readonly GestorArco $arco,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $organizacionId = $this->contexto->id();
        abort_if($organizacionId === null, 403);

        $solicitudes = SolicitudArco::query()
            ->where('organizacion_id', $organizacionId)
            ->when($peticion->query('estado'), fn ($consulta, $estado) => $consulta
                ->where('estado', $estado))
            ->with('persona')
            ->orderBy('vence_respuesta')
            ->limit(200)
            ->get();

        return response()->json([
            'datos' => $solicitudes->map(fn (SolicitudArco $solicitud): array => [
                'uuid' => $solicitud->uuid,
                'derecho' => $solicitud->derecho,
                'estado' => $solicitud->estado,
                'persona_uuid' => $solicitud->persona->uuid,
                'recibida_en' => $solicitud->recibida_en->toIso8601String(),
                'vence_respuesta' => $solicitud->vence_respuesta->toDateString(),
                'vencida' => $solicitud->vencida(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $validado = $peticion->validate([
            'persona_uuid' => ['required', 'uuid'],
            'derecho' => ['required', 'in:acceso,rectificacion,cancelacion,oposicion'],
            'descripcion' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $titular = Persona::query()->where('uuid', $validado['persona_uuid'])->firstOrFail();

        $solicitud = $this->arco->recibir(
            $titular,
            $this->actor($peticion),
            $validado['derecho'],
            $validado['descripcion'],
        );

        return response()->json([
            'uuid' => $solicitud->uuid,
            'vence_respuesta' => $solicitud->vence_respuesta->toDateString(),
            'aviso' => 'La organización tiene 20 días hábiles para responder.',
        ], 201);
    }

    public function responder(Request $peticion, SolicitudArco $solicitud): JsonResponse
    {
        $validado = $peticion->validate([
            'estado' => ['required', 'in:procedente,improcedente'],
            'respuesta' => ['required', 'string', 'min:10', 'max:5000'],
            'excepciones' => ['nullable', 'string', 'max:5000'],
        ]);

        abort_if($solicitud->organizacion_id !== $this->contexto->id(), 404);

        try {
            $resuelta = $this->arco->responder(
                $solicitud,
                $this->actor($peticion),
                $validado['estado'],
                $validado['respuesta'],
                $validado['excepciones'] ?? null,
            );
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['respuesta' => $error->getMessage()]);
        }

        return response()->json([
            'uuid' => $resuelta->uuid,
            'estado' => $resuelta->estado,
            'vence_cumplimiento' => $resuelta->vence_cumplimiento?->toDateString(),
        ]);
    }

    /**
     * La exportación del expediente: el efecto técnico del derecho de ACCESO.
     */
    public function exportar(Request $peticion, SolicitudArco $solicitud): JsonResponse
    {
        abort_if($solicitud->organizacion_id !== $this->contexto->id(), 404);
        abort_if($solicitud->derecho !== 'acceso', 422);

        return response()->json($this->arco->exportarExpediente($solicitud));
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
