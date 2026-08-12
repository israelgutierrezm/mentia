<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

/**
 * Estado de la API v1.
 *
 * Es lo que un cliente consulta para saber si la versión que trae sigue
 * atendida antes de mandar nada. La app Flutter instalada en un teléfono no se
 * actualiza porque nosotros publiquemos una v2, así que necesita poder
 * preguntarlo.
 */
class EstadoController extends ApiV1Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'version' => 'v1',
            'estado' => 'operativa',
            'documentacion' => '/docs/07-especificacion-api-v1.md',
        ]);
    }
}
