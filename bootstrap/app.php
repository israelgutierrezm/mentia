<?php

declare(strict_types=1);

use App\Http\Api\Problema;
use App\Http\Middleware\CompartirConInertia;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * API versionada desde el primer módulo (Doc 02 §2, regla 3).
             *
             * La versión va en el ARCHIVO, no sólo en el prefijo: cuando exista
             * una v2 será routes/api/v2.php con sus propios controllers en
             * Controllers/Api/V2, y la v1 seguirá respondiendo intacta a los
             * clientes que no han migrado —la app Flutter instalada en un
             * teléfono no se actualiza porque nosotros publiquemos—.
             */
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api/v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Inertia comparte el contexto de sesión con todas las páginas Vue.
        $middleware->web(append: [
            CompartirConInertia::class,
        ]);

        /*
         * Sanctum en el grupo api: permite que la SPA web autentique por cookie
         * de sesión (dominios de SANCTUM_STATEFUL_DOMAINS) mientras la app
         * Flutter, los tokens anónimos de aplicación y los terceros autentican
         * por token. Es el mismo contrato para todos.
         */
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * La API v1 falla en RFC 7807 SIEMPRE, incluso si el cliente no mandó
         * Accept: application/json. Un 500 en HTML dentro de una respuesta que
         * la app Flutter va a pasar por un parser de JSON no le dice nada al
         * que está depurando.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $peticion): bool => $peticion->is('api/*') || $peticion->expectsJson()
        );

        $exceptions->render(function (Throwable $excepcion, Request $peticion) {
            if (! $peticion->is('api/*')) {
                return null;
            }

            return Problema::desde($excepcion, $peticion);
        });
    })->create();
