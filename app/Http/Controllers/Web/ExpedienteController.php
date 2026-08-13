<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

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

class ExpedienteController extends Controller
{
    public function __construct(
        private readonly VistaExpediente $vista,
        private readonly CapturaExpediente $captura,
    ) {}

    /**
     * El expediente de una persona, filtrado sección por sección.
     *
     * Sin `can:` en la ruta: decide AccesoService por sección, y el titular
     * llega a la suya sin ningún permiso.
     */
    public function show(Request $peticion, Persona $persona): Response
    {
        $actor = $this->actor($peticion);

        return Inertia::render('Expediente/Ficha', [
            'persona' => [
                'uuid' => $persona->uuid,
                'nombre_completo' => $persona->nombreCompleto(),
            ],
            'secciones' => $this->vista->paraActor($persona, $actor),
        ]);
    }

    public function store(Request $peticion, Persona $persona): RedirectResponse
    {
        $validado = $peticion->validate([
            'campo_id' => ['required', 'integer'],
            'valor' => ['nullable'],
        ]);

        $campo = ExpedienteCampo::query()->findOrFail($validado['campo_id']);

        try {
            $this->captura->capturar(
                $persona,
                $campo,
                $validado['valor'] ?? null,
                $this->actor($peticion)
            );
        } catch (CapturaNoPermitida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Dato capturado.');
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();

        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
