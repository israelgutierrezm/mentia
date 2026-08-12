<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resuelve la organización activa de la petición.
 *
 * De dónde sale, en este orden:
 *  - API: encabezado `X-Organizacion` (Doc 07 §1).
 *  - Web: la organización guardada en sesión al iniciar sesión o al conmutar.
 *
 * Y SIEMPRE se comprueba que el actor esté vinculado a ella con vínculo
 * activo. Sin esa comprobación, `X-Organizacion: 7` sería una fuga de una
 * línea: el global scope filtraría obedientemente por el tenant que el
 * atacante pidió, y devolvería sus datos completos.
 */
class ResolverOrganizacion
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $organizacionId = $this->organizacionSolicitada($peticion);

        if ($organizacionId === null) {
            throw new AccessDeniedHttpException(
                'No hay organización activa en la sesión.'
            );
        }

        $persona = $peticion->user()?->persona;

        if ($persona === null) {
            throw new AccessDeniedHttpException('La cuenta no tiene una persona asociada.');
        }

        $vinculo = OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacionId)
            ->where('persona_id', $persona->id)
            ->where('estado', 'activa')
            ->first();

        if ($vinculo === null) {
            /*
             * Mismo mensaje que si la organización no existiera. Distinguir
             * "no existe" de "existe y no eres de ahí" le confirmaría a quien
             * pregunta qué organizaciones hay dadas de alta en la plataforma.
             */
            throw new AccessDeniedHttpException('No tienes acceso a esa organización.');
        }

        $organizacion = Organizacion::query()->find($organizacionId);

        if ($organizacion === null || ! $organizacion->estaActiva()) {
            throw new AccessDeniedHttpException('No tienes acceso a esa organización.');
        }

        $this->contexto->establecer($organizacion);

        return $siguiente($peticion);
    }

    private function organizacionSolicitada(Request $peticion): ?int
    {
        $encabezado = $peticion->header('X-Organizacion');

        if (is_string($encabezado) && ctype_digit($encabezado)) {
            return (int) $encabezado;
        }

        $enSesion = $peticion->hasSession()
            ? $peticion->session()->get('organizacion_id')
            : null;

        return is_int($enSesion) ? $enSesion : null;
    }
}
