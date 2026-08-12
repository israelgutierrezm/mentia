<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Contexto que toda página Vue recibe sin pedirlo.
 *
 * Aquí NO se resuelven permisos ni alcances: eso es de AccesoService. Lo que
 * se comparte es lo que la interfaz necesita para dibujarse —quién eres, en
 * qué organización estás parado, qué avisos hay— y llega ya filtrado por el
 * servidor.
 */
class CompartirConInertia extends Middleware
{
    /**
     * La plantilla Blade que envuelve la SPA.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $peticion): array
    {
        return [
            ...parent::share($peticion),

            'usuario' => null,

            /*
             * La organización activa (tenant) y el rol activo se resuelven en
             * la Fase 1, cuando exista el middleware de resolución de tenant.
             * Van declarados desde ahora para que el layout no tenga que
             * preguntar si la llave existe.
             */
            'organizacion' => null,
            'rolActivo' => null,

            'avisos' => [
                'exito' => fn () => $peticion->session()->get('exito'),
                'error' => fn () => $peticion->session()->get('error'),
            ],
        ];
    }
}
