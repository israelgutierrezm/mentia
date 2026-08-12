<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurarEloquent();
        $this->configurarLimites();
    }

    private function configurarEloquent(): void
    {
        /*
         * Modo estricto fuera de producción: revienta ante consultas N+1,
         * asignaciones masivas no declaradas y lecturas de atributos que no se
         * cargaron. Los tres producen, en producción, un sistema que funciona
         * mal en silencio —y aquí "mal en silencio" puede significar una
         * pantalla de resultados que no muestra una bandera clínica—.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        // Nada de destruir tablas por accidente desde un comando de consola.
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    private function configurarLimites(): void
    {
        /*
         * Límite por omisión de la API (Doc 07). Se afina por endpoint en la
         * Fase 9: el canje de token anónimo y el login necesitan un tope mucho
         * más bajo que una consulta de catálogo.
         *
         * La llave es el usuario cuando hay sesión y la IP cuando no: contra
         * ligas anónimas —donde media escuela sale por la misma IP— limitar
         * sólo por IP castigaría a todo un grupo por el tráfico de uno.
         */
        RateLimiter::for('api', function (Request $peticion): Limit {
            $usuario = $peticion->user();

            return $usuario !== null
                ? Limit::perMinute(120)->by('usuario:'.$usuario->getAuthIdentifier())
                : Limit::perMinute(60)->by('ip:'.$peticion->ip());
        });
    }
}
