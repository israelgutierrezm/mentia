<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\TipoAgrupacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Organizaciones\Servicios\GestorAgrupaciones;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaAgrupacionRequest;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AgrupacionController extends Controller
{
    public function __construct(
        private readonly GestorAgrupaciones $gestor,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Organizaciones/Agrupaciones', [
            'agrupaciones' => Agrupacion::query()
                ->withCount('miembrosVigentes')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'estado', 'unidad_id', 'tipo_agrupacion_id']),

            'unidades' => Unidad::query()->orderBy('nombre')->get(['id', 'nombre']),

            'tipos' => TipoAgrupacion::query()
                ->disponiblesPara((int) $this->contexto->id())
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }

    public function store(GuardaAgrupacionRequest $peticion): RedirectResponse
    {
        $this->gestor->crear($peticion->validated());

        return back(303)->with('exito', 'Agrupación creada.');
    }

    public function update(
        GuardaAgrupacionRequest $peticion,
        Agrupacion $agrupacion,
    ): RedirectResponse {
        $this->gestor->actualizar($agrupacion, $peticion->validated());

        return back(303)->with('exito', 'Agrupación actualizada.');
    }
}
