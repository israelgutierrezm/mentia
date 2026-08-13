<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accesos\CatalogoSecciones;
use App\Domain\Accesos\Datos\Seccion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Contexto que toda página Vue recibe sin pedirlo.
 *
 * Aquí NO se resuelven permisos ni alcances: eso es de AccesoService. Lo que se
 * comparte es lo que la interfaz necesita para dibujarse —quién eres, en qué
 * organización estás parado, a qué secciones llegas, qué avisos hay— y llega ya
 * filtrado por el servidor.
 *
 * El MENÚ se arma desde `CatalogoSecciones` filtrado por los permisos del rol
 * activo. Mandar la lista completa y esconder con `v-if` en el cliente sería
 * decirle al navegador qué existe: cualquiera abriría las herramientas de
 * desarrollo para ver el mapa de un sistema al que no tiene acceso.
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
        $usuario = $peticion->user();
        $persona = $usuario instanceof User ? $usuario->persona : null;
        $organizacion = app(ContextoOrganizacion::class)->organizacion();

        return [
            ...parent::share($peticion),

            'usuario' => $persona === null ? null : [
                'uuid' => $persona->uuid,
                'nombre' => $persona->nombreCompleto(),
            ],

            'organizacion' => $organizacion === null ? null : [
                'id' => $organizacion->id,
                'nombre' => $organizacion->nombre,
            ],

            'rolActivo' => null,

            /*
             * Perezoso: el menú consulta permisos y cuenta alertas, y una
             * petición parcial de Inertia —recargar una tabla al filtrar— no
             * necesita ninguna de las dos.
             */
            'menu' => fn (): array => $persona === null || $organizacion === null
                ? []
                : $this->menuDe($persona, $organizacion->id),

            'avisos' => [
                'exito' => fn () => $peticion->session()->get('exito'),
                'error' => fn () => $peticion->session()->get('error'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuDe(\App\Domain\Personas\Modelos\Persona $persona, int $organizacionId): array
    {
        $secciones = app(CatalogoSecciones::class)->para($persona);

        $alertasAbiertas = null;

        foreach ($secciones as $seccion) {
            if ($seccion->conContador) {
                // Se cuenta UNA sola vez y sólo si hay una sección que lo pida.
                $alertasAbiertas ??= Alerta::query()
                    ->where('organizacion_id', $organizacionId)
                    ->abiertas()
                    ->count();
            }
        }

        return array_map(
            static fn (Seccion $seccion): array => $seccion->paraLaVista(
                $seccion->conContador ? $alertasAbiertas : null
            ),
            $secciones,
        );
    }
}
