<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Accesos\Servicios\RegistroBitacora;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Modelos\PlantillaReporte;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Genera reportes y los guarda como PDF.
 *
 * EL PDF SE GUARDA, NO SE REGENERA. Un reporte es un documento entregado:
 * alguien lo imprimió y lo puso en un expediente o se lo dio a una familia. Si
 * el catálogo cambia mañana —se corrige un baremo, se reescribe una
 * interpretación— el papel que esa persona tiene en la mano tiene que seguir
 * explicándose. Regenerarlo al vuelo produciría un documento distinto con el
 * mismo folio.
 *
 * La AUDIENCIA la impone quien llama (viene de `VistaResultados`, que la deriva
 * del rol). Aquí no se elige.
 */
class GeneradorReportes
{
    public function __construct(
        private readonly VistaResultados $vista,
        private readonly RenderizadorPlantilla $renderizador,
        private readonly RegistroBitacora $bitacora,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * Reporte individual de una aplicación.
     *
     * @throws ReporteNoGenerable
     * @throws \App\Domain\Interpretacion\Excepciones\ResultadoNoDisponible
     *                                                                      Cuando quien pide no alcanza el resultado, o la aplicación es anónima.
     */
    public function individual(Persona $actor, Aplicacion $aplicacion): ReporteGenerado
    {
        $resultado = $this->vista->para($actor, $aplicacion);
        $audiencia = (string) $resultado['audiencia'];

        $plantilla = PlantillaReporte::resolver(
            'individual',
            $audiencia,
            $aplicacion->organizacion_id,
            $aplicacion->version_instrumento_id,
        );

        if ($plantilla === null) {
            throw ReporteNoGenerable::porFaltarPlantilla('individual', $audiencia);
        }

        $html = $this->renderizador->render(
            $plantilla->estructura_html,
            $this->datosIndividuales($aplicacion, $resultado),
        );

        return $this->guardar(
            organizacionId: $aplicacion->organizacion_id,
            tipo: 'individual',
            audiencia: $audiencia,
            html: $html,
            plantilla: $plantilla,
            generadoPor: $actor,
            personaId: $aplicacion->persona_id,
            aplicacionId: $aplicacion->id,
        );
    }

    /**
     * Reporte longitudinal de una persona: la "ficha de hospital".
     *
     * @throws ReporteNoGenerable
     */
    public function longitudinal(Persona $actor, Persona $titular): ReporteGenerado
    {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw ReporteNoGenerable::porFaltarOrganizacion();
        }

        $plantilla = PlantillaReporte::resolver('longitudinal', 'profesional', $organizacionId);

        if ($plantilla === null) {
            throw ReporteNoGenerable::porFaltarPlantilla('longitudinal', 'profesional');
        }

        $html = $this->renderizador->render(
            $plantilla->estructura_html,
            $this->datosLongitudinales($titular),
        );

        return $this->guardar(
            organizacionId: $organizacionId,
            tipo: 'longitudinal',
            audiencia: 'profesional',
            html: $html,
            plantilla: $plantilla,
            generadoPor: $actor,
            personaId: $titular->id,
        );
    }

    /**
     * Firma el reporte. Es lo que lo convierte de borrador en documento.
     *
     * Un reporte psicométrico sin firma es un borrador: el diagnóstico y la
     * responsabilidad son actos de una persona con cédula, no del sistema
     * (principio P6).
     */
    public function firmar(ReporteGenerado $reporte, Persona $quienFirma): ReporteGenerado
    {
        if ($reporte->estaFirmado()) {
            throw ReporteNoGenerable::porYaEstarFirmado();
        }

        $borrador = $reporte->borradorIa;

        /*
         * Un reporte con borrador de IA NO se firma sin que alguien haya
         * validado ese borrador. La firma dice "yo respondo por esto"; firmar
         * texto que nadie leyó es exactamente lo que el Doc 05 §6 prohíbe.
         */
        if ($borrador !== null && ! $borrador->estaValidado()) {
            throw ReporteNoGenerable::porBorradorSinValidar();
        }

        $reporte->update([
            'firmado_por' => $quienFirma->id,
            'firmado_en' => Carbon::now(),
        ]);

        $this->bitacora->registrarAccion(
            organizacionId: $reporte->organizacion_id,
            accion: 'reporte.firmado',
            recursoTipo: 'ReporteGenerado',
            recursoId: $reporte->id,
            personaAfectadaId: $reporte->persona_id,
            motivo: 'Firma profesional',
        );

        return $reporte->refresh();
    }

    /**
     * El PDF, para descargarlo. Deja bitácora SIEMPRE.
     *
     * Quién se llevó qué resultado y cuándo es justo lo que la LFPDPPP obliga
     * a poder demostrar.
     */
    public function contenidoPdf(ReporteGenerado $reporte, Persona $quienDescarga): string
    {
        $ruta = $this->rutaDe($reporte);

        if (! Storage::disk('local')->exists($ruta)) {
            throw ReporteNoGenerable::porArchivoPerdido($reporte->uuid);
        }

        $this->bitacora->registrarAccion(
            organizacionId: $reporte->organizacion_id,
            accion: 'reporte.descargado',
            recursoTipo: 'ReporteGenerado',
            recursoId: $reporte->id,
            personaAfectadaId: $reporte->persona_id,
            motivo: 'Descarga por '.$quienDescarga->id,
        );

        return (string) Storage::disk('local')->get($ruta);
    }

    public function rutaDe(ReporteGenerado $reporte): string
    {
        return sprintf('reportes/%d/%s.pdf', $reporte->organizacion_id, $reporte->uuid);
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function datosIndividuales(Aplicacion $aplicacion, array $resultado): array
    {
        return [
            'instrumento' => $resultado['instrumento'],
            'fecha' => $resultado['fecha'],
            'validez' => $resultado['validez'],

            /*
             * El nombre sale VACÍO en aplicaciones anónimas. Un reporte
             * individual de una NOM-035 anónima no existe, pero si alguien
             * pidiera uno, lo último que puede hacer el sistema es rellenar el
             * hueco con el nombre de alguien.
             */
            'persona' => $aplicacion->persona_id === null
                ? ''
                : $aplicacion->persona->nombreCompleto(),

            'escalas' => $resultado['escalas'] ?? [],
            'interpretaciones' => $resultado['interpretaciones'],
            'generado_en' => Carbon::now()->format('d/m/Y'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosLongitudinales(Persona $titular): array
    {
        $serie = ResultadoNormalizado::query()
            ->where('persona_id', $titular->id)
            ->orderBy('constructo')
            ->orderBy('fecha')
            ->get();

        $constructos = [];

        foreach ($serie->groupBy('constructo') as $constructo => $puntos) {
            $constructos[] = [
                'constructo' => (string) $constructo,
                'puntos' => $puntos->map(
                    static fn (ResultadoNormalizado $punto): array => [
                        'fecha' => $punto->fecha->toDateString(),
                        'valor' => $punto->valor,
                        'tipo_norma' => $punto->tipo_norma,
                        'bandera' => $punto->bandera ?? '',
                    ]
                )->values()->all(),
            ];
        }

        return [
            'persona' => $titular->nombreCompleto(),
            'constructos' => $constructos,
            'generado_en' => Carbon::now()->format('d/m/Y'),
        ];
    }

    private function guardar(
        int $organizacionId,
        string $tipo,
        string $audiencia,
        string $html,
        PlantillaReporte $plantilla,
        Persona $generadoPor,
        ?int $personaId = null,
        ?int $aplicacionId = null,
        ?int $asignacionId = null,
    ): ReporteGenerado {
        return DB::transaction(function () use (
            $organizacionId, $tipo, $audiencia, $html, $plantilla,
            $generadoPor, $personaId, $aplicacionId, $asignacionId
        ): ReporteGenerado {
            $reporte = ReporteGenerado::query()->create([
                'organizacion_id' => $organizacionId,
                'tipo' => $tipo,
                'audiencia' => $audiencia,
                'persona_id' => $personaId,
                'asignacion_id' => $asignacionId,
                'aplicacion_id' => $aplicacionId,
                'plantilla_id' => $plantilla->id,
                'generado_por' => $generadoPor->id,
                'generado_en' => Carbon::now(),
            ]);

            Storage::disk('local')->put($this->rutaDe($reporte), $this->aPdf($html));

            $this->bitacora->registrarAccion(
                organizacionId: $organizacionId,
                accion: 'reporte.generado',
                recursoTipo: 'ReporteGenerado',
                recursoId: $reporte->id,
                personaAfectadaId: $personaId,
                motivo: $tipo.' / '.$audiencia,
            );

            return $reporte;
        });
    }

    private function aPdf(string $html): string
    {
        $dompdf = new Dompdf([
            /*
             * SIN acceso remoto. Una plantilla que un tenant edita podría
             * apuntar a `http://localhost/…` y convertir el generador de PDF en
             * un lector de la red interna. Las imágenes que hagan falta van
             * incrustadas.
             */
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
