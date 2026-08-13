<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alertas\Excepciones\AlertaSinResolucion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Alertas\Servicios\AlertaService;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §6 — alertas.
 *
 * El listado NO trae el detalle de la respuesta que disparó la alerta: trae qué
 * pasó, de quién y qué tan grave. Ver el reactivo concreto es entrar al
 * expediente, y eso pasa por AccesoService con su propia bitácora.
 */
class AlertaController extends ApiV1Controller
{
    public function __construct(
        private readonly AlertaService $alertas,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $organizacionId = $this->contexto->id();
        abort_if($organizacionId === null, 403);

        $alertas = Alerta::query()
            ->where('organizacion_id', $organizacionId)
            ->when($peticion->query('estado'), fn ($consulta, $estado) => $consulta
                ->where('estado', $estado))
            ->when($peticion->query('severidad'), fn ($consulta, $severidad) => $consulta
                ->where('severidad', $severidad))
            ->with('persona')

            // Las críticas primero, y dentro de cada nivel las más viejas
            // arriba: una alerta crítica de hace tres días es peor noticia que
            // una de hace diez minutos.
            ->orderByRaw("FIELD(severidad, 'critica', 'alta', 'media')")
            ->orderBy('creada_en')
            ->paginate(50);

        return response()->json([
            'datos' => $alertas->through(fn (Alerta $alerta): array => [
                'id' => $alerta->id,
                'tipo' => $alerta->tipo,
                'severidad' => $alerta->severidad,
                'estado' => $alerta->estado,
                'mensaje' => $alerta->mensaje,
                'persona_uuid' => $alerta->persona?->uuid,
                'creada_en' => $alerta->creada_en->toIso8601String(),
                'atendida_en' => $alerta->atendida_en?->toIso8601String(),
            ])->items(),
            'total' => $alertas->total(),
        ]);
    }

    /**
     * Cerrar una alerta EXIGE decir qué se hizo (Doc 06 §5).
     */
    public function atender(Request $peticion, Alerta $alerta): JsonResponse
    {
        $validado = $peticion->validate([
            'resolucion' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        $this->exigirMismaOrganizacion($alerta);

        try {
            $cerrada = $this->alertas->atender(
                $alerta,
                $usuario->persona,
                $validado['resolucion'],
            );
        } catch (AlertaSinResolucion $error) {
            throw ValidationException::withMessages(['resolucion' => $error->getMessage()]);
        }

        return response()->json([
            'id' => $cerrada->id,
            'estado' => $cerrada->estado,
            'atendida_en' => $cerrada->atendida_en?->toIso8601String(),
        ]);
    }

    /**
     * Una alerta de otra organización responde 404, no 403.
     *
     * Un 403 confirmaría que esa alerta existe, y una alerta que existe
     * significa que alguien de esa organización dio positivo en algo.
     */
    private function exigirMismaOrganizacion(Alerta $alerta): void
    {
        abort_if($alerta->organizacion_id !== $this->contexto->id(), 404);
    }
}
