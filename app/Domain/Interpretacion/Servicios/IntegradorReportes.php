<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Accesos\Servicios\RegistroBitacora;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Contratos\RedactaBorradores;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Modelos\PlantillaReporte;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Modelos\ReporteIa;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El reporte integrador con IA (Doc 05 §6).
 *
 * TRES REGLAS Y NINGUNA SE NEGOCIA:
 *
 * 1. **Nace como borrador.** No hay camino por el que un texto de IA salga
 *    liberado sin que una persona con cédula lo lea.
 * 2. **El insumo va pseudonimizado.** Lo garantiza `ArmadorInsumoIA`, no quien
 *    llama: si cada llamador armara el suyo, tarde o temprano uno metería el
 *    nombre "para que el reporte quede mejor".
 * 3. **Validar exige permiso `ia.validar_reportes`.** Y rechazarlo también deja
 *    constancia: un borrador rechazado dice tanto como uno aceptado.
 */
class IntegradorReportes
{
    public function __construct(
        private readonly ArmadorInsumoIA $armador,
        private readonly RedactaBorradores $redactor,
        private readonly RenderizadorPlantilla $renderizador,
        private readonly RegistroBitacora $bitacora,
    ) {}

    /**
     * @param  list<Aplicacion>  $aplicaciones
     *
     * @throws ReporteNoGenerable
     * @throws \App\Domain\Interpretacion\Excepciones\BorradorNoRedactable
     * @throws RuntimeException
     */
    public function generar(
        Persona $solicita,
        Persona $titular,
        array $aplicaciones,
        int $organizacionId,
    ): ReporteGenerado {
        if ($aplicaciones === []) {
            throw new RuntimeException('Un reporte integrador necesita al menos una aplicación.');
        }

        $plantilla = PlantillaReporte::resolver('integrador', 'profesional', $organizacionId);

        if ($plantilla === null) {
            throw ReporteNoGenerable::porFaltarPlantilla('integrador', 'profesional');
        }

        $insumo = $this->armador->para($aplicaciones);
        $borrador = $this->redactor->redactar($insumo);

        return DB::transaction(function () use (
            $solicita, $titular, $organizacionId, $plantilla, $insumo, $borrador
        ): ReporteGenerado {
            $reporte = ReporteGenerado::query()->create([
                'organizacion_id' => $organizacionId,
                'tipo' => 'integrador',
                'audiencia' => 'profesional',
                'persona_id' => $titular->id,
                'plantilla_id' => $plantilla->id,
                'generado_por' => $solicita->id,
                'generado_en' => Carbon::now(),
            ]);

            ReporteIa::query()->create([
                'reporte_generado_id' => $reporte->id,
                'modelo' => $this->redactor->modelo(),
                'insumo_hash' => $this->armador->hashDe($insumo),
                'borrador' => $borrador,
                'estado' => 'borrador',
            ]);

            $this->bitacora->registrarAccion(
                organizacionId: $organizacionId,
                accion: 'reporte.integrador_generado',
                recursoTipo: 'ReporteGenerado',
                recursoId: $reporte->id,
                personaAfectadaId: $titular->id,
                motivo: 'Borrador de IA con modelo '.$this->redactor->modelo(),
            );

            return $reporte;
        });
    }

    /**
     * Valida o rechaza el borrador. Exige el permiso `ia.validar_reportes`.
     *
     * El texto validado puede venir CORREGIDO: quien valida no aprueba o
     * rechaza a ciegas, edita. Es la diferencia entre revisar y sellar.
     */
    public function validar(
        ReporteIa $borrador,
        Persona $quienValida,
        bool $aprueba,
        ?string $textoCorregido = null,
        ?string $observaciones = null,
    ): ReporteIa {
        if (! $quienValida->hasPermissionTo('ia.validar_reportes', 'web')) {
            throw new RuntimeException('Falta el permiso para validar reportes de IA.');
        }

        if ($borrador->estado !== 'borrador') {
            throw new RuntimeException('Este borrador ya se validó o se rechazó.');
        }

        /*
         * Rechazar SIN decir por qué deja el expediente con un borrador muerto
         * y sin explicación. Quien lo lea en seis meses tiene que poder saber
         * si se rechazó porque el texto estaba mal o porque los datos lo
         * estaban.
         */
        if (! $aprueba && ($observaciones === null || trim($observaciones) === '')) {
            throw new RuntimeException('Rechazar un borrador exige decir por qué.');
        }

        $borrador->update([
            'estado' => $aprueba ? 'validado' : 'rechazado',
            'borrador' => $aprueba && $textoCorregido !== null ? $textoCorregido : $borrador->borrador,
            'validado_por' => $quienValida->id,
            'validado_en' => Carbon::now(),
            'observaciones_validacion' => $observaciones,
        ]);

        $this->bitacora->registrarAccion(
            organizacionId: $borrador->reporte->organizacion_id,
            accion: $aprueba ? 'reporte.ia_validado' : 'reporte.ia_rechazado',
            recursoTipo: 'ReporteIa',
            recursoId: $borrador->id,
            personaAfectadaId: $borrador->reporte->persona_id,
            motivo: $observaciones,
        );

        return $borrador->refresh();
    }

    /**
     * El HTML del reporte integrador, con el borrador ya validado dentro.
     */
    public function html(ReporteGenerado $reporte): string
    {
        $borrador = $reporte->borradorIa;
        $plantilla = PlantillaReporte::query()->find($reporte->plantilla_id);

        if ($borrador === null || $plantilla === null) {
            throw ReporteNoGenerable::porFaltarPlantilla('integrador', 'profesional');
        }

        return $this->renderizador->render($plantilla->estructura_html, [
            'persona' => $reporte->persona?->nombreCompleto() ?? '',
            'texto' => $borrador->borrador,
            'modelo' => $borrador->modelo,
            'estado' => $borrador->estado,
            'generado_en' => $reporte->generado_en->format('d/m/Y'),
        ]);
    }
}
