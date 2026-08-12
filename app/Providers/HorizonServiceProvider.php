<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeMailNotificationsTo('operacion@mentia.mx');
    }

    /**
     * Quién puede entrar a Horizon fuera de local.
     *
     * El tablero de Horizon expone el payload de los jobs, y por esa cola pasan
     * identificadores de personas y de aplicaciones. No es una pantalla de
     * infraestructura inocua: es material del expediente.
     *
     * Lista vacía a propósito = NADIE en producción hasta que la Fase 1 traiga
     * el permiso `plataforma.ver_horizon` y esto pase a consultarlo. Un
     * `return true` provisional se queda para siempre.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $usuario = null): bool => false);
    }
}
