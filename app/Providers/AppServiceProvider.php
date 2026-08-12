<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accesos\CatalogoPermisos;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * SINGLETON, no binding normal. Si cada `app(ContextoOrganizacion)`
         * devolviera una instancia nueva, el middleware fijaría la
         * organización en una y el global scope leería otra vacía: todas las
         * consultas de tenant devolverían cero filas y nadie entendería por
         * qué.
         */
        $this->app->singleton(ContextoOrganizacion::class);
    }

    public function boot(): void
    {
        $this->configurarEsquema();
        $this->configurarEloquent();
        $this->configurarLimites();
        $this->configurarAutorizacion();
    }

    /**
     * Hace que el `can:` de Laravel resuelva contra los permisos de la PERSONA.
     *
     * Los roles cuelgan de `personas`, no de `users` (Doc 03 §M3), así que el
     * `permission:` de Spatie —que mira el modelo autenticado— no sirve aquí:
     * daría siempre false y todas las pantallas responderían 403.
     *
     * Sólo intercepta las llaves del catálogo. Devolver null para el resto
     * deja pasar a las policies y a los gates que se definan después; si esto
     * contestara a todo, un `Gate::define` propio quedaría muerto sin aviso.
     */
    private function configurarAutorizacion(): void
    {
        Gate::before(function (User $usuario, string $habilidad): ?bool {
            if (! CatalogoPermisos::existe($habilidad)) {
                return null;
            }

            $persona = $usuario->persona;

            if ($persona === null) {
                return false;
            }

            return $persona->hasPermissionTo($habilidad, 'web');
        });
    }

    /**
     * Macros de Blueprint que fijan las convenciones del Doc 03 en las
     * migraciones.
     */
    private function configurarEsquema(): void
    {
        /*
         * "Todas las tablas llevan creado_en, actualizado_en" (Doc 03,
         * encabezado). Como macro y no a mano en cada migración: escribirlos
         * uno por uno es como una tabla termina con `created_at` en inglés y
         * el modelo dejando de registrar la fecha sin que nada falle.
         */
        Blueprint::macro('sellosDeTiempo', function (): void {
            /** @var Blueprint $this */
            $this->timestamp('creado_en')->nullable();
            $this->timestamp('actualizado_en')->nullable();
        });

        /*
         * Discriminador de tenant con su índice. La columna sola no basta: sin
         * índice, cada consulta filtrada por organización barre la tabla
         * completa, y `respuestas` se proyecta en decenas de millones de filas.
         */
        Blueprint::macro('organizacion', function (bool $nullable = false): void {
            /** @var Blueprint $this */
            $columna = $this->foreignId('organizacion_id');

            if ($nullable) {
                $columna->nullable();
            }

            $columna->constrained('organizaciones')->cascadeOnDelete();
        });
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

        /*
         * Los modelos viven en app/Domain/<Dominio>/Modelos/, no en app/Models,
         * así que la convención de Laravel para encontrar su factory no aplica.
         * Se resuelve por nombre de clase: Persona → PersonaFactory.
         */
        Factory::guessFactoryNamesUsing(
            static fn (string $modelo): string => 'Database\\Factories\\'.class_basename($modelo).'Factory'
        );
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
