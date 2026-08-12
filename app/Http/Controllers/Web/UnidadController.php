<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Organizaciones\Servicios\GestorUnidades;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaUnidadRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UnidadController extends Controller
{
    public function __construct(private readonly GestorUnidades $gestor) {}

    public function index(): Response
    {
        return Inertia::render('Organizaciones/Unidades', [
            'unidades' => Unidad::query()
                ->orderBy('unidad_padre_id')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'tipo', 'estado', 'unidad_padre_id']),
        ]);
    }

    public function store(GuardaUnidadRequest $peticion): RedirectResponse
    {
        $this->gestor->crear($peticion->validated());

        return back(303)->with('exito', 'Unidad creada.');
    }

    public function update(GuardaUnidadRequest $peticion, Unidad $unidad): RedirectResponse
    {
        $this->gestor->actualizar($unidad, $peticion->validated());

        // 303 y no 302: ante un 302 el navegador repite el PUT contra la
        // pantalla destino, que sólo responde GET, y termina en 405 aunque el
        // cambio ya se haya guardado.
        return back(303)->with('exito', 'Unidad actualizada.');
    }
}
