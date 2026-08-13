<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalogo\Excepciones\HabilitacionInvalida;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Catalogo\Servicios\ConsultaCatalogo;
use App\Domain\Catalogo\Servicios\GestorTenantInstrumentos;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de habilitación del tenant: qué instrumentos puede aplicar esta
 * organización y qué le falta para encender los demás.
 */
class HabilitacionController extends Controller
{
    public function __construct(
        private readonly ConsultaCatalogo $consulta,
        private readonly GestorTenantInstrumentos $gestor,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Catalogo/Habilitacion', [
            'instrumentos' => $this->consulta->estadoDelTenant(),
        ]);
    }

    public function habilitar(VersionInstrumento $version): RedirectResponse
    {
        try {
            if ($version->instrumento->exigeLicenciaDelTenant()) {
                $registro = TenantInstrumento::query()
                    ->where('version_instrumento_id', $version->id)
                    ->firstOrFail();

                $this->gestor->habilitarTrasCapturarContenido($registro);
            } else {
                $this->gestor->habilitarDominioPublico($version);
            }
        } catch (HabilitacionInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Instrumento habilitado.');
    }

    public function declararLicencia(Request $peticion, VersionInstrumento $version): RedirectResponse
    {
        $validado = $peticion->validate([
            'declaracion' => ['required', 'string', 'min:20'],
        ]);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        try {
            $this->gestor->declararLicencia(
                $version,
                $usuario->persona,
                $validado['declaracion']
            );
        } catch (HabilitacionInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with(
            'exito',
            'Declaración registrada. Ahora captura el contenido para poder habilitarlo.'
        );
    }
}
