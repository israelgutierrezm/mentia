<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Excepciones\BorradorNoRedactable;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Excepciones\ResultadoNoDisponible;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Servicios\GeneradorReportes;
use App\Domain\Interpretacion\Servicios\IntegradorReportes;
use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Doc 07 §6 — reportes.
 *
 * La DESCARGA pasa por AccesoService y deja bitácora: quién se llevó qué
 * resultado y cuándo es justo lo que la LFPDPPP obliga a poder demostrar.
 */
class ReporteController extends ApiV1Controller
{
    public function __construct(
        private readonly GeneradorReportes $generador,
        private readonly IntegradorReportes $integrador,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * Genera el reporte individual de una aplicación.
     */
    public function individual(Request $peticion, Aplicacion $aplicacion): JsonResponse
    {
        $actor = $this->actor($peticion);

        try {
            $reporte = $this->generador->individual($actor, $aplicacion);
        } catch (ReporteNoGenerable $error) {
            throw ValidationException::withMessages(['reporte' => $error->getMessage()]);
        } catch (ResultadoNoDisponible $error) {
            // El acceso no procede o la aplicación es anónima: 404 opaco. Un
            // 403 confirmaría que esa persona fue evaluada aquí.
            return $this->noEncontrado();
        }

        return response()->json($this->resumen($reporte), 201);
    }

    /**
     * Encola —conceptualmente— el integrador con IA. Responde 202 porque el
     * borrador no es un resultado: es material que alguien tiene que revisar.
     */
    public function integrador(Request $peticion): JsonResponse
    {
        $validado = $peticion->validate([
            'persona_uuid' => ['required', 'uuid'],
            'aplicaciones' => ['required', 'array', 'min:1', 'max:20'],
            'aplicaciones.*' => ['uuid'],
        ]);

        $actor = $this->actor($peticion);
        $organizacionId = $this->contexto->id();
        abort_if($organizacionId === null, 403);

        $titular = Persona::query()->where('uuid', $validado['persona_uuid'])->first();

        if ($titular === null) {
            return $this->noEncontrado();
        }

        $aplicaciones = Aplicacion::query()
            ->whereIn('uuid', $validado['aplicaciones'])
            ->where('persona_id', $titular->id)
            ->where('estado', 'completada')
            ->get()
            ->all();

        try {
            $reporte = $this->integrador->generar($actor, $titular, $aplicaciones, $organizacionId);
        } catch (BorradorNoRedactable|ReporteNoGenerable $error) {
            throw ValidationException::withMessages(['integrador' => $error->getMessage()]);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['aplicaciones' => $error->getMessage()]);
        }

        return response()->json([
            ...$this->resumen($reporte),
            'aviso' => 'El borrador requiere validación profesional antes de liberarse.',
        ], 202);
    }

    public function show(Request $peticion, ReporteGenerado $reporte): JsonResponse
    {
        $this->exigirMismaOrganizacion($reporte);

        $borrador = $reporte->borradorIa;

        return response()->json([
            ...$this->resumen($reporte),
            'borrador_ia' => $borrador === null ? null : [
                'modelo' => $borrador->modelo,
                'estado' => $borrador->estado,
                'texto' => $borrador->borrador,
                'validado_en' => $borrador->validado_en?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Validar o rechazar el borrador de IA. Exige `ia.validar_reportes`.
     */
    public function validarBorrador(Request $peticion, ReporteGenerado $reporte): JsonResponse
    {
        $validado = $peticion->validate([
            'aprueba' => ['required', 'boolean'],
            'texto_corregido' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->exigirMismaOrganizacion($reporte);

        $borrador = $reporte->borradorIa;

        if ($borrador === null) {
            return $this->noEncontrado();
        }

        try {
            $resultado = $this->integrador->validar(
                $borrador,
                $this->actor($peticion),
                (bool) $validado['aprueba'],
                $validado['texto_corregido'] ?? null,
                $validado['observaciones'] ?? null,
            );
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['aprueba' => $error->getMessage()]);
        }

        return response()->json(['estado' => $resultado->estado]);
    }

    public function firmar(Request $peticion, ReporteGenerado $reporte): JsonResponse
    {
        $this->exigirMismaOrganizacion($reporte);

        try {
            $firmado = $this->generador->firmar($reporte, $this->actor($peticion));
        } catch (ReporteNoGenerable $error) {
            throw ValidationException::withMessages(['firma' => $error->getMessage()]);
        }

        return response()->json([
            'uuid' => $firmado->uuid,
            'firmado_en' => $firmado->firmado_en?->toIso8601String(),
        ]);
    }

    /**
     * Descarga del PDF. Deja bitácora SIEMPRE.
     */
    public function descargar(Request $peticion, ReporteGenerado $reporte): Response
    {
        $this->exigirMismaOrganizacion($reporte);

        $contenido = $this->generador->contenidoPdf($reporte, $this->actor($peticion));

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="reporte-'.$reporte->uuid.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(ReporteGenerado $reporte): array
    {
        return [
            'uuid' => $reporte->uuid,
            'tipo' => $reporte->tipo,
            'audiencia' => $reporte->audiencia,
            'generado_en' => $reporte->generado_en->toIso8601String(),
            'firmado' => $reporte->estaFirmado(),
        ];
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }

    private function exigirMismaOrganizacion(ReporteGenerado $reporte): void
    {
        abort_if($reporte->organizacion_id !== $this->contexto->id(), 404);
    }

    private function noEncontrado(): JsonResponse
    {
        return response()->json([
            'type' => 'https://mentia.mx/problemas/no-encontrado',
            'title' => 'No encontrado',
            'status' => 404,
            'detail' => 'No existe ese recurso o no está disponible.',
        ], 404, ['Content-Type' => 'application/problem+json']);
    }
}
