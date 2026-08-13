<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalogo\Modelos\CategoriaInstrumento;
use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Servicios\ConsultaCatalogo;
use App\Http\Controllers\Controller;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de catálogo. Muestra la ficha técnica, nunca los reactivos: el
 * contenido sólo sale parcelado durante la aplicación (Doc 06 §3).
 */
class CatalogoController extends Controller
{
    public function __construct(
        private readonly ConsultaCatalogo $consulta,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): Response
    {
        $pagina = $this->consulta->buscar([
            'categoria' => $peticion->query('categoria'),
            'dominio' => $peticion->query('dominio'),
            'estatus_licencia' => $peticion->query('estatus_licencia'),
            'texto' => $peticion->query('texto'),
        ]);

        return Inertia::render('Catalogo/Index', [
            'instrumentos' => $pagina->getCollection()
                ->map(fn (Instrumento $instrumento): array => [
                    'clave' => $instrumento->clave,
                    'nombre' => $instrumento->nombre,
                    'dominio' => $instrumento->dominio->nombre,
                    'estatus_licencia' => $instrumento->estatus_licencia,
                    'nivel_sensibilidad' => $instrumento->nivelSensibilidad(),
                    'versiones_publicadas' => $instrumento->versiones_publicadas_count ?? 0,
                    'se_aplica_en_linea' => $instrumento->seAplicaEnLinea(),
                ])->all(),

            'dominios' => Dominio::query()->orderBy('orden')->get(['clave', 'nombre']),
            'categorias' => CategoriaInstrumento::query()
                ->whereNotNull('padre_id')->orderBy('orden')->get(['clave', 'nombre']),
            'filtros' => $peticion->only(['categoria', 'dominio', 'estatus_licencia', 'texto']),
        ]);
    }

    public function show(string $clave): Response
    {
        $instrumento = Instrumento::query()
            ->visiblesPara($this->contexto->id())
            ->where('clave', $clave)
            ->firstOrFail();

        return Inertia::render('Catalogo/Ficha', [
            'instrumento' => $this->consulta->ficha($instrumento),
        ]);
    }
}
