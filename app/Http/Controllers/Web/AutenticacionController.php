<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrar y salir.
 *
 * NO HAY REGISTRO. Las personas las da de alta la organización: alguien que se
 * registra solo no está vinculado a ningún tenant, no tiene rol y no puede ver
 * nada — pero sí habría creado una cuenta con un correo que quizá no es suyo,
 * en un sistema que guarda expedientes clínicos.
 *
 * Quien contesta por liga anónima NO pasa por aquí: su credencial es el token
 * de aplicación y puede no tener cuenta (Doc 07 §5).
 */
class AutenticacionController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    public function mostrar(): Response
    {
        return Inertia::render('Auth/Entrar');
    }

    public function entrar(Request $peticion): RedirectResponse
    {
        $validado = $peticion->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $llave = $this->llaveDeIntentos($peticion);

        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS_MAXIMOS)) {
            throw ValidationException::withMessages([
                'email' => sprintf(
                    'Demasiados intentos. Espera %d segundos.',
                    RateLimiter::availableIn($llave),
                ),
            ]);
        }

        if (! Auth::attempt($validado, $peticion->boolean('recordarme'))) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);

            /*
             * Un solo mensaje para correo inexistente y contraseña equivocada.
             * Distinguirlos convierte el formulario en un verificador de qué
             * correos tienen cuenta aquí, y tener cuenta aquí ya dice algo de
             * una persona.
             */
            throw ValidationException::withMessages([
                'email' => 'Esos datos no coinciden con ninguna cuenta.',
            ]);
        }

        RateLimiter::clear($llave);
        $peticion->session()->regenerate();

        $this->fijarOrganizacionInicial($peticion);

        return redirect()->intended('/');
    }

    public function salir(Request $peticion): RedirectResponse
    {
        Auth::guard('web')->logout();

        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        return redirect('/entrar');
    }

    /**
     * Deja activa la primera organización vigente de la persona.
     *
     * Sin organización activa los global scopes fallan cerrado y todas las
     * pantallas salen vacías: quien entra y ve un sistema en blanco concluye
     * que está roto, no que le falta elegir tenant. Quien pertenece a varias
     * lo cambia después; entrar a la primera es mejor que entrar a ninguna.
     */
    private function fijarOrganizacionInicial(Request $peticion): void
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof User) {
            return;
        }

        $organizacionId = OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->where('persona_id', $usuario->persona_id)
            ->where('estado', 'activa')
            ->orderBy('id')
            ->value('organizacion_id');

        if ($organizacionId !== null) {
            $peticion->session()->put('organizacion_id', $organizacionId);
        }
    }

    private function llaveDeIntentos(Request $peticion): string
    {
        // Por correo Y por IP: sólo por correo, cualquiera bloquearía la cuenta
        // ajena a propósito; sólo por IP, una oficina entera comparte castigo.
        return 'entrar:'.mb_strtolower((string) $peticion->input('email')).'|'.$peticion->ip();
    }
}
