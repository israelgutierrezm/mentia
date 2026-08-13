<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Consentimientos\Modelos\TextoConsentimiento;
use App\Domain\Expedientes\Excepciones\CapturaNoPermitida;
use App\Domain\Expedientes\Modelos\ExpedienteCampo;
use App\Domain\Expedientes\Servicios\CapturaExpediente;
use App\Domain\Expedientes\Servicios\VistaExpediente;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal de autollenado del TITULAR.
 *
 * No lleva permiso ni organización activa: la persona entra a lo suyo. Es la
 * pantalla que la LFPDPPP hace necesaria —el titular tiene que poder ver,
 * corregir y decidir sobre sus datos— y la que hace que el expediente no
 * dependa de que alguien capture por ella.
 */
class PortalExpedienteController extends Controller
{
    public function __construct(
        private readonly VistaExpediente $vista,
        private readonly CapturaExpediente $captura,
    ) {}

    public function index(Request $peticion): Response
    {
        $persona = $this->titular($peticion);

        return Inertia::render('Portal/MiExpediente', [
            'secciones' => $this->vista->paraAutollenado($persona),

            'consentimientos' => Consentimiento::query()
                ->where('persona_id', $persona->id)
                ->with('texto')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Consentimiento $consentimiento): array => [
                    'id' => $consentimiento->id,
                    'titulo' => $consentimiento->texto->titulo,
                    'estado' => $consentimiento->estado,
                    'relacion' => $consentimiento->relacion,
                    'vigente' => $consentimiento->estaVigente(),
                    'otorgado_en' => $consentimiento->vigencia_inicio->toDateString(),
                ])->all(),

            'textos_disponibles' => TextoConsentimiento::query()
                ->whereNull('organizacion_id')
                ->with('tipo')
                ->get()
                ->map(fn (TextoConsentimiento $texto): array => [
                    'id' => $texto->id,
                    'titulo' => $texto->titulo,
                    'cuerpo' => $texto->cuerpo,
                    'tipo' => $texto->tipo->nombre,
                ])->all(),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $validado = $peticion->validate([
            'campo_id' => ['required', 'integer'],
            'valor' => ['nullable'],
        ]);

        $persona = $this->titular($peticion);
        $campo = ExpedienteCampo::query()->findOrFail($validado['campo_id']);

        try {
            $this->captura->capturar($persona, $campo, $validado['valor'] ?? null, $persona);
        } catch (CapturaNoPermitida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with(
            'exito',
            'Dato guardado. Queda pendiente de que la organización lo valide.'
        );
    }

    private function titular(Request $peticion): Persona
    {
        $usuario = $peticion->user();

        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
