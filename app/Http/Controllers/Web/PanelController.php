<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de inicio del administrador.
 *
 * Placeholder de la Fase 0: sostiene el layout y confirma que la cadena
 * Inertia → Vue → Vite está armada. El panel real se registra por tarjetas
 * declaradas con su permiso en la Fase 1, no con ramas por rol.
 */
class PanelController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Panel', [
            'fase' => 0,
        ]);
    }
}
