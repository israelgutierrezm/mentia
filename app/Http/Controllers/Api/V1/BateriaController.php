<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\BateriaInvalida;
use App\Domain\Evaluaciones\Modelos\Bateria;
use App\Domain\Evaluaciones\Modelos\BateriaInstrumento;
use App\Domain\Evaluaciones\Servicios\GestorBaterias;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §3: GET/POST/PUT /baterias · /baterias/{id}/instrumentos
 *
 * Espejo del editor web: MISMO GestorBaterias.
 */
class BateriaController extends ApiV1Controller
{
    public function __construct(
        private readonly GestorBaterias $gestor,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): JsonResponse
    {
        $baterias = Bateria::query()
            ->disponiblesPara($this->contexto->id())
            ->with('instrumentos.version.instrumento')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => $baterias->map(fn (Bateria $bateria): array => [
                'id' => $bateria->id,
                'clave' => $bateria->clave,
                'nombre' => $bateria->nombre,
                'estado' => $bateria->estado,
                'orden_instrumentos' => $bateria->orden_instrumentos,
                'permite_pausas' => $bateria->permite_pausas,
                'instrumentos' => $bateria->instrumentos->map(
                    fn (BateriaInstrumento $renglon): array => [
                        'id' => $renglon->id,
                        'version_instrumento_id' => $renglon->version_instrumento_id,
                        'instrumento' => $renglon->version->instrumento->clave,
                        'orden' => $renglon->orden,
                        'obligatorio' => $renglon->obligatorio,
                    ]
                )->all(),
            ])->all(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $validado = $peticion->validate([
            'clave' => ['required', 'string', 'max:60', 'alpha_dash'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ]);

        $bateria = $this->gestor->crear(
            $validado['clave'],
            $validado['nombre'],
            $validado['descripcion'] ?? null
        );

        return response()->json(['id' => $bateria->id, 'clave' => $bateria->clave], 201);
    }

    public function update(Request $peticion, Bateria $bateria): JsonResponse
    {
        $validado = $peticion->validate([
            'nombre' => ['sometimes', 'string', 'max:160'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'orden_instrumentos' => ['sometimes', 'in:fijo,libre'],
            'permite_pausas' => ['sometimes', 'boolean'],
            'tiempo_total_min' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $this->gestor->actualizar($bateria, $validado);

        return response()->json(['id' => $bateria->id]);
    }

    public function agregar(Request $peticion, Bateria $bateria): JsonResponse
    {
        $validado = $peticion->validate([
            'version_instrumento_id' => ['required', 'integer'],
            'obligatorio' => ['sometimes', 'boolean'],
        ]);

        $version = VersionInstrumento::query()->findOrFail($validado['version_instrumento_id']);

        try {
            $renglon = $this->gestor->agregar($bateria, $version, $validado['obligatorio'] ?? true);
        } catch (BateriaInvalida $error) {
            throw ValidationException::withMessages([
                'version_instrumento_id' => $error->getMessage(),
            ]);
        }

        return response()->json(['id' => $renglon->id, 'orden' => $renglon->orden], 201);
    }

    public function quitar(Bateria $bateria, BateriaInstrumento $renglon): JsonResponse
    {
        try {
            $this->gestor->quitar($bateria, $renglon);
        } catch (BateriaInvalida $error) {
            throw ValidationException::withMessages(['bateria' => $error->getMessage()]);
        }

        return response()->json(status: 204);
    }

    /**
     * Recibe el orden COMPLETO. Mandar sólo el renglón movido dejaría al
     * servidor recalculando lo que el cliente ya sabe.
     */
    public function reordenar(Request $peticion, Bateria $bateria): JsonResponse
    {
        $validado = $peticion->validate([
            'orden' => ['required', 'array', 'min:1'],
            'orden.*' => ['integer'],
        ]);

        try {
            $this->gestor->reordenar($bateria, $validado['orden']);
        } catch (BateriaInvalida $error) {
            throw ValidationException::withMessages(['orden' => $error->getMessage()]);
        }

        return response()->json(['orden' => $validado['orden']]);
    }

    public function activar(Bateria $bateria): JsonResponse
    {
        try {
            $this->gestor->activar($bateria);
        } catch (BateriaInvalida $error) {
            throw ValidationException::withMessages(['bateria' => $error->getMessage()]);
        }

        return response()->json(['estado' => $bateria->refresh()->estado]);
    }
}
