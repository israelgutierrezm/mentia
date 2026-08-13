<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Servicios\CapturaProtocolo;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaCapturaProtocoloRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Captura del protocolo de papel (Doc 07 §5).
 *
 * El examinador aplicó el WISC en su cuadernillo y aquí registra los puntajes.
 * La pantalla ofrece SÓLO los instrumentos que no se pueden aplicar en línea:
 * ofrecer los demás invitaría a saltarse el motor —y con él los cronómetros,
 * los tiempos por reactivo y los índices de validez— para producir un resultado
 * que se ve igual y no lo es.
 */
class CapturaProtocoloController extends Controller
{
    public function __construct(private readonly CapturaProtocolo $captura) {}

    public function index(): Response
    {
        return Inertia::render('Aplicacion/CapturaProtocolo', [
            'instrumentos' => $this->versionesCapturables(),
        ]);
    }

    public function store(GuardaCapturaProtocoloRequest $peticion): RedirectResponse
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        $validado = $peticion->validated();

        try {
            $this->captura->registrar(
                personaUuid: $validado['persona_uuid'],
                versionInstrumentoId: $validado['version_instrumento_id'],
                fechaAplicacion: $validado['fecha_aplicacion'],
                escalas: $validado['escalas'],
                capturadoPor: $usuario->persona,
                observaciones: $validado['observaciones'] ?? null,
            );
        } catch (RuntimeException $error) {
            return back()->withErrors(['escalas' => $error->getMessage()]);
        }

        return back()->with('exito', 'Se registró el protocolo y quedó encolada su calificación.');
    }

    /**
     * Las versiones publicadas de instrumentos de sólo captura, con sus escalas.
     *
     * Las escalas viajan a la pantalla porque el formulario se dibuja con
     * ellas: capturar un WISC son quince renglones con nombre propio, y pedirle
     * al examinador que escriba la clave de cada uno es pedirle que se
     * equivoque.
     *
     * @return list<array<string, mixed>>
     */
    private function versionesCapturables(): array
    {
        $versiones = VersionInstrumento::query()
            ->publicadas()
            ->whereHas('instrumento', fn ($consulta) => $consulta
                ->where('estatus_licencia', Instrumento::SOLO_CAPTURA))
            ->with('instrumento')
            ->get();

        return $versiones->map(fn (VersionInstrumento $version): array => [
            'version_instrumento_id' => $version->id,
            'nombre' => $version->instrumento->nombre,
            'version' => $version->version,
            'escalas' => Escala::query()
                ->where('version_instrumento_id', $version->id)
                ->orderBy('orden')
                ->get()
                ->map(fn (Escala $escala): array => [
                    'clave' => $escala->clave,
                    'nombre' => $escala->nombre,
                ])->values()->all(),
        ])->values()->all();
    }
}
