<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Excepciones\HabilitacionInvalida;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Catalogo\Servicios\ConsultaCatalogo;
use App\Domain\Catalogo\Servicios\GestorTenantInstrumentos;
use App\Domain\Catalogo\Servicios\ImportadorInstrumento;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §3: habilitación y captura de contenido por tenant.
 */
class TenantInstrumentoController extends ApiV1Controller
{
    public function __construct(
        private readonly ConsultaCatalogo $consulta,
        private readonly GestorTenantInstrumentos $gestor,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->consulta->estadoDelTenant()]);
    }

    /**
     * Habilitar. Dominio público directo; con copyright exige que la
     * declaración ya esté firmada y el contenido capturado.
     */
    public function habilitar(VersionInstrumento $version): JsonResponse
    {
        try {
            $registro = $version->instrumento->exigeLicenciaDelTenant()
                ? $this->gestor->habilitarTrasCapturarContenido($this->registroDe($version))
                : $this->gestor->habilitarDominioPublico($version);
        } catch (HabilitacionInvalida $error) {
            throw ValidationException::withMessages(['habilitacion' => $error->getMessage()]);
        }

        return response()->json([
            'estado' => $registro->estado,
            'se_puede_asignar' => $registro->sePuedeAsignar(),
        ]);
    }

    public function declararLicencia(Request $peticion, VersionInstrumento $version): JsonResponse
    {
        $validado = $peticion->validate([
            'declaracion' => ['required', 'string', 'min:20'],
            'evidencia_media_id' => ['nullable', 'integer'],
        ]);

        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        try {
            $registro = $this->gestor->declararLicencia(
                $version,
                $usuario->persona,
                $validado['declaracion'],
                $validado['evidencia_media_id'] ?? null
            );
        } catch (HabilitacionInvalida $error) {
            throw ValidationException::withMessages(['declaracion' => $error->getMessage()]);
        }

        return response()->json([
            'estado' => $registro->estado,
            'aviso' => 'Declaración registrada. Falta capturar el contenido antes de habilitar.',
        ], 201);
    }

    /**
     * Carga la plantilla Excel con el contenido que el tenant captura bajo su
     * licencia.
     *
     * Devuelve el reporte fila a fila SIEMPRE, con o sin errores: es lo que
     * quien capturó la hoja necesita para corregirla.
     */
    public function importarContenido(
        Request $peticion,
        VersionInstrumento $version,
        ImportadorInstrumento $importador,
    ): JsonResponse {
        $peticion->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $registro = $this->registroDe($version);

        if (! $registro->tieneDeclaracionFirmada()) {
            throw ValidationException::withMessages([
                'archivo' => 'Antes de capturar contenido hay que firmar la declaración de licencia.',
            ]);
        }

        /*
         * El contenido se marca como PRIVADO de esta organización. Es lo que
         * impide que llegue a otro tenant, y no es una preferencia: es la
         * cadena de responsabilidad ante la editorial.
         */
        $reporte = $importador->importar(
            $peticion->file('archivo')->getRealPath(),
            organizacionIdContenido: $this->contexto->id(),
            versionExistente: $version,
        );

        return response()->json(
            $reporte->paraRespuesta(),
            $reporte->tieneErrores() ? 422 : 200
        );
    }

    private function registroDe(VersionInstrumento $version): TenantInstrumento
    {
        return TenantInstrumento::query()
            ->where('version_instrumento_id', $version->id)
            ->firstOrFail();
    }
}
