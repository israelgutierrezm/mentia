<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\BateriaInvalida;
use App\Domain\Evaluaciones\Modelos\Bateria;
use App\Domain\Evaluaciones\Modelos\BateriaInstrumento;
use App\Domain\Evaluaciones\Servicios\GestorBaterias;
use App\Http\Controllers\Controller;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BateriaController extends Controller
{
    public function __construct(
        private readonly GestorBaterias $gestor,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Evaluaciones/Baterias', [
            'baterias' => Bateria::query()
                ->where('organizacion_id', $this->contexto->id())
                ->with('instrumentos.version.instrumento')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Bateria $bateria): array => [
                    'id' => $bateria->id,
                    'clave' => $bateria->clave,
                    'nombre' => $bateria->nombre,
                    'descripcion' => $bateria->descripcion,
                    'estado' => $bateria->estado,
                    'orden_instrumentos' => $bateria->orden_instrumentos,
                    'permite_pausas' => $bateria->permite_pausas,
                    'instrumentos' => $bateria->instrumentos->map(
                        fn (BateriaInstrumento $renglon): array => [
                            'id' => $renglon->id,
                            'version_id' => $renglon->version_instrumento_id,
                            'nombre' => $renglon->version->instrumento->nombre,
                            'version' => $renglon->version->etiqueta(),
                            'obligatorio' => $renglon->obligatorio,
                            'duracion' => $renglon->version->instrumento->duracion_estimada_min,
                        ]
                    )->all(),
                ])->all(),

            // Sólo lo que esta organización puede aplicar.
            'disponibles' => $this->gestor->versionesDisponibles()
                ->map(fn (VersionInstrumento $version): array => [
                    'id' => $version->id,
                    'nombre' => $version->instrumento->nombre,
                    'version' => $version->etiqueta(),
                    'duracion' => $version->instrumento->duracion_estimada_min,
                ])->values()->all(),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $validado = $peticion->validate([
            'clave' => ['required', 'string', 'max:60', 'alpha_dash'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->gestor->crear(
            $validado['clave'],
            $validado['nombre'],
            $validado['descripcion'] ?? null
        );

        return back(303)->with('exito', 'Batería creada.');
    }

    public function agregar(Request $peticion, Bateria $bateria): RedirectResponse
    {
        $validado = $peticion->validate([
            'version_instrumento_id' => ['required', 'integer'],
            'obligatorio' => ['sometimes', 'boolean'],
        ]);

        $version = VersionInstrumento::query()->findOrFail($validado['version_instrumento_id']);

        try {
            $this->gestor->agregar($bateria, $version, $validado['obligatorio'] ?? true);
        } catch (BateriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Instrumento agregado.');
    }

    public function quitar(Bateria $bateria, BateriaInstrumento $renglon): RedirectResponse
    {
        try {
            $this->gestor->quitar($bateria, $renglon);
        } catch (BateriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Instrumento retirado.');
    }

    /**
     * Recibe el orden COMPLETO tras arrastrar.
     */
    public function reordenar(Request $peticion, Bateria $bateria): RedirectResponse
    {
        $validado = $peticion->validate([
            'orden' => ['required', 'array', 'min:1'],
            'orden.*' => ['integer'],
        ]);

        try {
            $this->gestor->reordenar($bateria, $validado['orden']);
        } catch (BateriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Orden guardado.');
    }

    public function activar(Bateria $bateria): RedirectResponse
    {
        try {
            $this->gestor->activar($bateria);
        } catch (BateriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Batería activada: ya se puede asignar.');
    }

    public function archivar(Bateria $bateria): RedirectResponse
    {
        $this->gestor->archivar($bateria);

        return back(303)->with('exito', 'Batería archivada.');
    }
}
