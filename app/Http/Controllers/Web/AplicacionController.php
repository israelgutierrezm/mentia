<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de quien CONTESTA. Pública.
 *
 * Es la única del sistema sin sesión y sin organización activa: quien recibe
 * una liga por correo puede no tener cuenta en nada. El controller no resuelve
 * el token —ni lo ve—: sólo entrega la página, y el canje lo hace el navegador
 * por POST contra `/api/v1/aplicaciones/iniciar`.
 *
 * Eso no es un rodeo. El token viaja en el FRAGMENTO de la liga
 * (`/contestar#<token>`), y el fragmento no llega al servidor: así la
 * credencial de quien contesta no se escribe en el log de accesos, ni en el
 * proxy corporativo, ni en el `Referer`.
 */
class AplicacionController extends Controller
{
    public function contestar(): Response
    {
        return Inertia::render('Aplicacion/Canje');
    }
}
