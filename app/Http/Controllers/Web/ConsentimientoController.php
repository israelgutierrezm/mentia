<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Consentimientos\Excepciones\ConsentimientoInvalido;
use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Consentimientos\Modelos\TextoConsentimiento;
use App\Domain\Consentimientos\Servicios\GestorConsentimientos;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Otorgar y revocar consentimientos, y compartir el expediente.
 *
 * Todo lo de aquí lo hace la PERSONA sobre sus propios datos, o un tutor
 * acreditado sobre los del menor. GestorConsentimientos rechaza a cualquier
 * otro, así que estas rutas no llevan permiso de organización.
 */
class ConsentimientoController extends Controller
{
    public function __construct(private readonly GestorConsentimientos $gestor) {}

    public function store(Request $peticion): RedirectResponse
    {
        $validado = $peticion->validate([
            'texto_consentimiento_id' => ['required', 'integer'],
            'titular_uuid' => ['nullable', 'uuid'],
            'vigencia_fin' => ['nullable', 'date', 'after:today'],
        ]);

        $otorgante = $this->actor($peticion);
        $texto = TextoConsentimiento::query()->findOrFail($validado['texto_consentimiento_id']);

        $titular = isset($validado['titular_uuid'])
            ? Persona::query()->where('uuid', $validado['titular_uuid'])->firstOrFail()
            : $otorgante;

        try {
            $this->gestor->otorgar(
                titular: $titular,
                texto: $texto,
                otorgante: $otorgante,
                vigenciaFin: $validado['vigencia_fin'] ?? null,
            );
        } catch (ConsentimientoInvalido $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Consentimiento otorgado.');
    }

    public function revocar(Request $peticion, Consentimiento $consentimiento): RedirectResponse
    {
        $actor = $this->actor($peticion);

        /*
         * Sólo el titular o quien lo otorgó pueden revocarlo. Que un tercero
         * pudiera revocar el consentimiento de alguien más sería tan grave
         * como que pudiera otorgarlo.
         */
        abort_unless(
            $actor->id === $consentimiento->persona_id
                || $actor->id === $consentimiento->otorgado_por_persona_id,
            403
        );

        $this->gestor->revocar(
            $consentimiento,
            $peticion->string('motivo')->toString() ?: null
        );

        return back(303)->with('exito', 'Consentimiento revocado. El acceso quedó cortado.');
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();

        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
