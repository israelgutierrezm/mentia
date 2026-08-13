<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Servicios\ConsultaCatalogo;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doc 07 §3: GET /catalogo/instrumentos · GET /catalogo/instrumentos/{clave}
 */
class CatalogoController extends ApiV1Controller
{
    public function __construct(
        private readonly ConsultaCatalogo $consulta,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $pagina = $this->consulta->buscar(
            [
                'categoria' => $peticion->query('categoria'),
                'dominio' => $peticion->query('dominio'),
                'estatus_licencia' => $peticion->query('estatus_licencia'),
                'texto' => $peticion->query('texto'),
            ],
            $this->limite((int) $peticion->query('limit', '0'))
        );

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (Instrumento $instrumento): array => [
                'clave' => $instrumento->clave,
                'nombre' => $instrumento->nombre,
                'dominio' => $instrumento->dominio->clave,
                'estatus_licencia' => $instrumento->estatus_licencia,
                'nivel_sensibilidad' => $instrumento->nivelSensibilidad(),
                'versiones_publicadas' => $instrumento->versiones_publicadas_count ?? 0,
                'se_aplica_en_linea' => $instrumento->seAplicaEnLinea(),
            ])->all(),
            'cursor' => $pagina->nextCursor()?->encode(),
        ]);
    }

    /**
     * Ficha por CLAVE, no por id: la clave es lo que un integrador conoce
     * (`phq9`) y lo que no cambia entre entornos.
     */
    public function show(string $clave): JsonResponse
    {
        $instrumento = Instrumento::query()
            ->visiblesPara($this->contexto->id())
            ->where('clave', $clave)
            ->firstOrFail();

        return response()->json($this->consulta->ficha($instrumento));
    }
}
