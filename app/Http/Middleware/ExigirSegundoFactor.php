<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accesos\Servicios\ExigenciaDeSegundoFactor;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea a quien tiene un rol de sensibilidad 3–4 y todavía no activó su
 * segundo factor (Doc 06 §4).
 *
 * BLOQUEA, no sugiere. Un aviso que se puede cerrar lo cierra todo el mundo, y
 * el control que protege el expediente clínico de un menor no puede depender de
 * que a alguien le dé por hacerle caso.
 *
 * Deja pasar la propia pantalla de activación y el cierre de sesión: encerrar a
 * la persona sin dejarle activar lo que se le exige convertiría la medida en un
 * candado sin llave.
 */
class ExigirSegundoFactor
{
    /** Rutas que siguen abiertas para poder cumplir con el requisito. */
    private const PERMITIDAS = ['seguridad/dos-factores', 'salir', 'entrar'];

    public function __construct(private readonly ExigenciaDeSegundoFactor $exigencia) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof User) {
            return $siguiente($peticion);
        }

        foreach (self::PERMITIDAS as $ruta) {
            if ($peticion->is($ruta) || $peticion->is($ruta.'/*')) {
                return $siguiente($peticion);
            }
        }

        if (! $this->exigencia->pendientePara($usuario)) {
            return $siguiente($peticion);
        }

        if ($peticion->expectsJson()) {
            return response()->json([
                'type' => 'https://mentia.mx/problemas/segundo-factor-requerido',
                'title' => 'Falta activar el segundo factor',
                'status' => 403,
                'detail' => 'Tu rol accede a información sensible y exige verificación en dos pasos.',
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        return redirect('/seguridad/dos-factores');
    }
}
