<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Alertas\Excepciones\AlertaSinResolucion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Alertas\Servicios\AlertaService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El centro de alertas (Doc 08, Fase 8).
 *
 * La bandeja donde quien está de guardia ve qué hay abierto. Las críticas
 * arriba y, dentro de cada nivel, las más VIEJAS primero: una alerta crítica de
 * hace tres días es peor noticia que una de hace diez minutos, y una bandeja
 * ordenada por fecha descendente esconde justamente la que se está pudriendo.
 */
class AlertaController extends Controller
{
    public function __construct(
        private readonly AlertaService $alertas,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): Response
    {
        $organizacionId = $this->contexto->id();
        abort_if($organizacionId === null, 403);

        $estado = (string) $peticion->query('estado', 'abiertas');

        $consulta = Alerta::query()
            ->where('organizacion_id', $organizacionId)
            ->with(['persona', 'atendidaPor'])
            ->orderByRaw("FIELD(severidad, 'critica', 'alta', 'media')")
            ->orderBy('creada_en');

        if ($estado === 'abiertas') {
            $consulta->abiertas();
        } elseif ($estado !== 'todas') {
            $consulta->where('estado', $estado);
        }

        return Inertia::render('Alertas/Centro', [
            'estado' => $estado,
            'alertas' => $consulta->limit(200)->get()->map(
                fn (Alerta $alerta): array => [
                    'id' => $alerta->id,
                    'tipo' => $alerta->tipo,
                    'severidad' => $alerta->severidad,
                    'estado' => $alerta->estado,
                    'mensaje' => $alerta->mensaje,
                    'persona' => $alerta->persona?->nombreCompleto(),
                    'persona_uuid' => $alerta->persona?->uuid,
                    'aplicacion_uuid' => $alerta->aplicacion?->uuid,
                    'creada_en' => $alerta->creada_en->toIso8601String(),
                    'atendida_por' => $alerta->atendidaPor?->nombreCompleto(),
                    'atendida_en' => $alerta->atendida_en?->toIso8601String(),
                    'resolucion' => $alerta->resolucion,
                ]
            )->values()->all(),

            'conteos' => [
                'criticas_abiertas' => Alerta::query()
                    ->where('organizacion_id', $organizacionId)
                    ->abiertas()
                    ->where('severidad', 'critica')
                    ->count(),
                'abiertas' => Alerta::query()
                    ->where('organizacion_id', $organizacionId)
                    ->abiertas()
                    ->count(),
            ],
        ]);
    }

    public function atender(Request $peticion, Alerta $alerta): RedirectResponse
    {
        $validado = $peticion->validate([
            'resolucion' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        abort_if($alerta->organizacion_id !== $this->contexto->id(), 404);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        try {
            $this->alertas->atender($alerta, $usuario->persona, $validado['resolucion']);
        } catch (AlertaSinResolucion $error) {
            return back()->withErrors(['resolucion' => $error->getMessage()]);
        }

        return back()->with('exito', 'Se cerró la alerta con su resolución registrada.');
    }
}
