<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\Servicios\ExigenciaDeSegundoFactor;
use App\Domain\Accesos\Servicios\SegundoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Alta del segundo factor.
 *
 * Esta pantalla sigue abierta aunque el middleware esté bloqueando todo lo
 * demás: encerrar a la persona sin dejarle activar lo que se le exige
 * convertiría la medida en un candado sin llave.
 */
class SegundoFactorController extends Controller
{
    public function __construct(
        private readonly SegundoFactor $factor,
        private readonly ExigenciaDeSegundoFactor $exigencia,
    ) {}

    public function mostrar(Request $peticion): Response
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        $yaLoTiene = $usuario->dos_factores_confirmado_en !== null;

        /*
         * El secreto se genera al ABRIR la pantalla, no antes. Generarlo al
         * asignar el rol dejaría secretos sin usar en la base de gente que
         * quizá nunca entra.
         */
        $secreto = $yaLoTiene ? null : $this->factor->preparar($usuario);

        return Inertia::render('Auth/DosFactores', [
            'activo' => $yaLoTiene,
            'obligatorio' => $this->exigencia->obligatorioPara($usuario->persona),
            'secreto' => $secreto,
            'url_alta' => $secreto === null ? null : $this->factor->urlDeAlta($usuario, $secreto),
        ]);
    }

    public function confirmar(Request $peticion): RedirectResponse
    {
        $validado = $peticion->validate([
            'codigo' => ['required', 'string', 'size:6'],
        ]);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        try {
            $codigos = $this->factor->confirmar($usuario, $validado['codigo']);
        } catch (RuntimeException $error) {
            return back()->withErrors(['codigo' => $error->getMessage()]);
        }

        /*
         * Los códigos de recuperación viajan UNA VEZ, en la sesión, y se
         * muestran en la pantalla siguiente. Guardarlos en claro para poder
         * volver a enseñarlos anularía el punto de cifrarlos.
         */
        return redirect('/seguridad/dos-factores')
            ->with('codigos_recuperacion', $codigos)
            ->with('exito', 'Verificación en dos pasos activada.');
    }
}
