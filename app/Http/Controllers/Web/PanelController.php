<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\CatalogoSecciones;
use App\Domain\Accesos\Datos\Seccion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El panel de inicio, armado por TARJETAS declaradas con su permiso.
 *
 * No hay ramas por rol: se filtra `CatalogoSecciones` por lo que la persona
 * puede hacer y se dibuja lo que queda. Una organización que define un rol
 * nuevo con otra combinación de permisos ve el panel que le corresponde sin que
 * nadie toque el código.
 *
 * Lo pendiente va arriba y con número. Un panel que abre con un saludo y deja
 * las tres alertas críticas abiertas escondidas en un menú lateral es un panel
 * que no sirve para trabajar.
 */
class PanelController extends Controller
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    public function __invoke(Request $peticion): Response
    {
        $usuario = $peticion->user();
        $organizacionId = $this->contexto->id();

        if (! $usuario instanceof User || $organizacionId === null) {
            return Inertia::render('Panel', [
                'tarjetas' => [],
                'pendientes' => [],
            ]);
        }

        $persona = $usuario->persona;
        $secciones = app(CatalogoSecciones::class)->para($persona);

        return Inertia::render('Panel', [
            'tarjetas' => array_map(
                static fn (Seccion $seccion): array => $seccion->paraLaVista(),
                $secciones,
            ),
            'pendientes' => $this->pendientesDe($persona, $organizacionId),
        ]);
    }

    /**
     * Lo que espera acción, con su liga.
     *
     * Cada tarjeta de pendiente comprueba su propio permiso: quien no atiende
     * alertas no tiene por qué enterarse de cuántas hay.
     *
     * @return list<array<string, mixed>>
     */
    private function pendientesDe(\App\Domain\Personas\Modelos\Persona $persona, int $organizacionId): array
    {
        $pendientes = [];

        if ($persona->hasPermissionTo('alertas.atender', 'web')) {
            $criticas = Alerta::query()
                ->where('organizacion_id', $organizacionId)
                ->abiertas()
                ->where('severidad', 'critica')
                ->count();

            $abiertas = Alerta::query()
                ->where('organizacion_id', $organizacionId)
                ->abiertas()
                ->count();

            if ($abiertas > 0) {
                $pendientes[] = [
                    'clave' => 'alertas',
                    'etiqueta' => $criticas > 0
                        ? $criticas.' '.($criticas === 1 ? 'alerta crítica abierta' : 'alertas críticas abiertas')
                        : $abiertas.' '.($abiertas === 1 ? 'alerta abierta' : 'alertas abiertas'),
                    'url' => '/alertas',
                    'urgente' => $criticas > 0,
                ];
            }
        }

        if ($persona->hasPermissionTo('evaluaciones.asignar', 'web')) {
            $activas = Asignacion::query()
                ->where('organizacion_id', $organizacionId)
                ->where('estado', 'activa')
                ->count();

            if ($activas > 0) {
                $pendientes[] = [
                    'clave' => 'asignaciones',
                    'etiqueta' => $activas.' '.($activas === 1
                        ? 'evaluación en curso'
                        : 'evaluaciones en curso'),
                    'url' => '/asignaciones',
                    'urgente' => false,
                ];
            }
        }

        return $pendientes;
    }
}
