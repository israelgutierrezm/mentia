<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluaciones\Excepciones\AsignacionInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Modelos\Proposito;
use App\Domain\Evaluaciones\Servicios\CreadorAsignaciones;
use App\Domain\Evaluaciones\Servicios\GestorAsignaciones;
use App\Domain\Evaluaciones\Servicios\NotificadorAsignaciones;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Http\Requests\GuardaAsignacionRequest;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §4.
 *
 * Las asignaciones se referencian por FOLIO, no por id: es lo que se dicta por
 * teléfono y lo que un integrador guarda en su sistema.
 */
class AsignacionController extends ApiV1Controller
{
    public function __construct(
        private readonly CreadorAsignaciones $creador,
        private readonly GestorAsignaciones $gestor,
        private readonly NotificadorAsignaciones $notificador,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $actor = $this->actor($peticion);

        $consulta = Asignacion::query()
            ->visiblesPara($actor, $this->nivelDe($peticion))
            ->with(['proposito', 'agrupacion'])
            ->withCount('destinatarios');

        if ($peticion->query('estado') !== null) {
            $consulta->where('estado', $peticion->query('estado'));
        }

        if ($peticion->query('proposito') !== null) {
            $consulta->whereHas('proposito', fn ($sub) => $sub->where('clave', $peticion->query('proposito')));
        }

        $pagina = $consulta->orderByDesc('id')
            ->cursorPaginate($this->limite((int) $peticion->query('limit', '0')));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (Asignacion $asignacion): array => [
                'folio' => $asignacion->folio,
                'proposito' => $asignacion->proposito->nombre,
                'origen_tipo' => $asignacion->origen_tipo,
                'agrupacion' => $asignacion->agrupacion?->nombre,
                'estado' => $asignacion->estado,
                'es_discreta' => $asignacion->es_discreta,
                'es_anonima' => $asignacion->es_anonima,
                'ventana_inicio' => $asignacion->ventana_inicio->toIso8601String(),
                'ventana_fin' => $asignacion->ventana_fin->toIso8601String(),
                'destinatarios' => $asignacion->destinatarios_count ?? 0,
            ])->all(),
            'cursor' => $pagina->nextCursor()?->encode(),
        ]);
    }

    public function store(GuardaAsignacionRequest $peticion): JsonResponse
    {
        $validado = $peticion->validated();

        // Los propósitos de plataforma MÁS los de esta organización; nunca los
        // de otra.
        $proposito = Proposito::query()
            ->disponiblesPara($this->contexto->id())
            ->findOrFail($validado['proposito_id']);

        $agrupacion = isset($validado['agrupacion_id'])
            ? Agrupacion::query()->findOrFail($validado['agrupacion_id'])
            : null;

        try {
            $asignacion = $this->creador->crear(
                proposito: $proposito,
                autor: $this->actor($peticion),
                origenTipo: $validado['origen_tipo'],
                agrupacion: $agrupacion,
                destinatariosUuid: $validado['destinatarios'] ?? [],
                ventanaInicio: $validado['ventana_inicio'] ?? null,
                ventanaFin: $validado['ventana_fin'] ?? null,
                versionInstrumentoId: $validado['version_instrumento_id'] ?? null,
                bateriaId: $validado['bateria_id'] ?? null,
                incluirNuevosMiembros: $validado['incluir_nuevos_miembros'] ?? false,
                esDiscreta: $validado['es_discreta'] ?? false,
                esAnonima: $validado['es_anonima'] ?? false,
                intentosPermitidos: $validado['intentos_permitidos'] ?? 1,
                modoPresentacion: $validado['modo_presentacion'] ?? null,
            );
        } catch (AsignacionInvalida $error) {
            throw ValidationException::withMessages(['asignacion' => $error->getMessage()]);
        }

        return response()->json([
            'folio' => $asignacion->folio,
            'destinatarios' => $asignacion->destinatarios()->count(),
        ], 201);
    }

    public function show(Request $peticion, Asignacion $asignacion): JsonResponse
    {
        $this->exigirVisible($peticion, $asignacion);

        return response()->json($this->gestor->avance($asignacion));
    }

    /**
     * Monitoreo por persona. RESPETA EL ANONIMATO: si la asignación es
     * anónima, no hay detalle que dar.
     */
    public function destinatarios(Request $peticion, Asignacion $asignacion): JsonResponse
    {
        $this->exigirVisible($peticion, $asignacion);

        try {
            $detalle = $this->gestor->destinatariosDetallados($asignacion);
        } catch (AsignacionInvalida $error) {
            return response()->json([
                'error' => $error->getMessage(),
                'avance' => $this->gestor->avance($asignacion),
            ], 409);
        }

        if ($peticion->query('estado') !== null) {
            $estado = $peticion->query('estado');
            $detalle = array_values(array_filter(
                $detalle,
                static fn (array $fila): bool => $fila['estado'] === $estado
            ));
        }

        return response()->json(['data' => $detalle]);
    }

    public function recordatorios(Request $peticion, Asignacion $asignacion): JsonResponse
    {
        $this->exigirVisible($peticion, $asignacion);

        return response()->json(['enviados' => $this->notificador->recordar($asignacion)]);
    }

    public function cerrar(Request $peticion, Asignacion $asignacion): JsonResponse
    {
        $this->exigirVisible($peticion, $asignacion);

        try {
            $this->gestor->cerrar($asignacion);
        } catch (AsignacionInvalida $error) {
            throw ValidationException::withMessages(['estado' => $error->getMessage()]);
        }

        return response()->json(['estado' => $asignacion->refresh()->estado]);
    }

    public function cancelar(Request $peticion, Asignacion $asignacion): JsonResponse
    {
        $this->exigirVisible($peticion, $asignacion);

        try {
            $this->gestor->cancelar($asignacion);
        } catch (AsignacionInvalida $error) {
            throw ValidationException::withMessages(['estado' => $error->getMessage()]);
        }

        return response()->json(['estado' => $asignacion->refresh()->estado]);
    }

    public function exentar(
        Request $peticion,
        Asignacion $asignacion,
        AsignacionDestinatario $destinatario,
    ): JsonResponse {
        $this->exigirVisible($peticion, $asignacion);

        abort_unless($destinatario->asignacion_id === $asignacion->id, 404);

        $validado = $peticion->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            $this->gestor->exentar($destinatario, $validado['motivo']);
        } catch (AsignacionInvalida $error) {
            throw ValidationException::withMessages(['motivo' => $error->getMessage()]);
        }

        return response()->json(['estado' => $destinatario->refresh()->estado]);
    }

    /**
     * Una discreta que no se ve devuelve 404, no 403: un 403 confirmaría que
     * ese folio existe, y la existencia de la asignación es justo lo que la
     * discreción protege.
     */
    private function exigirVisible(Request $peticion, Asignacion $asignacion): void
    {
        $visible = Asignacion::query()
            ->visiblesPara($this->actor($peticion), $this->nivelDe($peticion))
            ->whereKey($asignacion->id)
            ->exists();

        abort_unless($visible, 404);
    }

    private function actor(Request $peticion): \App\Domain\Personas\Modelos\Persona
    {
        $usuario = $peticion->user();

        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }

    /**
     * El nivel de sensibilidad del actor en esta organización.
     *
     * Se resuelve aquí y no en AccesoService porque la visibilidad de una
     * asignación no es acceso a datos de una persona: es acceso a la ORDEN.
     */
    private function nivelDe(Request $peticion): int
    {
        $roles = $this->actor($peticion)->roles()->with('topeSensibilidad')->get();

        $nivel = 1;

        foreach ($roles as $rol) {
            if ($rol instanceof \App\Domain\Accesos\Modelos\Rol) {
                $nivel = max($nivel, $rol->nivelSensibilidadMaximo());
            }
        }

        return $nivel;
    }
}
