<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Servicios\AgregadoGrupal;
use App\Domain\Interpretacion\Servicios\GeneradorReportes;
use App\Domain\Personas\Modelos\Persona;
use App\Jobs\Calificacion\EtapaBrutos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Reportes grupal y NOM-035.
 *
 * La regla que gobierna los dos es el TAMAÑO MÍNIMO. Un reporte grupal de tres
 * personas no es un agregado: es la lista de esas tres personas escrita de otra
 * forma.
 */
class ReportesGrupalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_un_grupo_demasiado_chico_no_genera_reporte(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 3);

        $this->expectException(ReporteNoGenerable::class);
        $this->expectExceptionMessageMatches('/identifica a las personas/');

        /*
         * En una NOM-035 anónima esto deshace el anonimato —el jefe sabe
         * quiénes son los tres— y con él la única razón por la que la gente
         * contestó con la verdad.
         */
        app(GeneradorReportes::class)->grupal($tenant->persona(), $asignacion);
    }

    public function test_con_el_minimo_si_se_genera(): void
    {
        Storage::fake('local');

        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 6);

        $reporte = app(GeneradorReportes::class)->grupal($tenant->persona(), $asignacion);

        $this->assertSame('grupal', $reporte->tipo);
        $this->assertSame($asignacion->id, $reporte->asignacion_id);

        Storage::disk('local')->assertExists(app(GeneradorReportes::class)->rutaDe($reporte));
    }

    public function test_el_agregado_no_nombra_a_nadie(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 6);

        $agregado = app(AgregadoGrupal::class)->para($asignacion);

        $serializado = (string) json_encode($agregado);

        foreach (Persona::query()->get() as $persona) {
            $this->assertStringNotContainsString($persona->nombres, $serializado);
        }

        // Lo que sí trae: conteos y estadísticos.
        $this->assertSame(6, $agregado['contestadas']);
        $this->assertNotEmpty($agregado['escalas']);
    }

    public function test_el_semaforo_agregado_cuenta_etiquetas_y_no_las_promedia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 6);

        $agregado = app(AgregadoGrupal::class)->para($asignacion);
        $escala = $agregado['escalas'][0];

        /*
         * Promediar categorías —«medio» y «muy alto» dan «alto»— produce un
         * número que no describe a nadie del grupo y esconde justamente a quien
         * está peor.
         */
        $this->assertIsArray($escala['distribucion']);
        $this->assertSame(6, array_sum($escala['distribucion']));
    }

    public function test_el_minimo_no_baja_de_cinco(): void
    {
        config(['mentia.reportes.minimo_grupo' => 1]);

        // Una organización que quisiera bajarlo estaría desactivando lo único
        // que impide que un reporte "agregado" señale a una persona.
        $this->assertSame(5, app(AgregadoGrupal::class)->minimoDeGrupo());
    }

    public function test_la_nom035_usa_el_mismo_agregado_con_otra_portada(): void
    {
        Storage::fake('local');

        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 6);

        $reporte = app(GeneradorReportes::class)
            ->grupal($tenant->persona(), $asignacion, 'nom035');

        $this->assertSame('nom035', $reporte->tipo);
        $this->assertSame(1, ReporteGenerado::query()->where('tipo', 'nom035')->count());
    }

    public function test_la_api_exige_el_permiso_de_reportes_grupales(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $asignacion = $this->asignacionContestadaPor($tenant, 6);

        $auxiliar = $tenant->persona();
        $tenant->asignarRol($auxiliar, $tenant->rol('Auxiliar', ['personas.ver'], 1));

        $this->actingAs($tenant->usuarioDe($auxiliar))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->postJson(
                '/api/v1/reportes/asignaciones/'.$asignacion->folio,
                [],
                ['X-Organizacion' => (string) $tenant->organizacion->id],
            )
            ->assertForbidden();
    }

    // ── Andamio ───────────────────────────────────────────────────────────

    /**
     * Una asignación con N personas que la contestaron y se calificaron.
     */
    private function asignacionContestadaPor(EscenarioTenant $tenant, int $cuantas): Asignacion
    {
        $escenario = new EscenarioAsignacion($tenant);
        $instrumento = $escenario->instrumento;

        $escala = $instrumento->escalas['E1'];

        \App\Domain\Catalogo\Modelos\EtapaPipeline::query()->create([
            'version_instrumento_id' => $instrumento->version->id,
            'etapa' => 'brutos',
            'estrategia_clave' => 'suma_ponderada',
            'orden' => 0,
        ]);

        $personas = [];

        foreach (range(1, $cuantas) as $ignorado) {
            $personas[] = $tenant->persona();
        }

        $asignacion = $escenario->individual($tenant->persona(), $personas);

        $reactivo = \App\Domain\Catalogo\Modelos\Reactivo::query()
            ->where('version_instrumento_id', $instrumento->version->id)
            ->firstOrFail();

        $opciones = $reactivo->opciones()->orderBy('orden')->get();

        // Con `with('persona')`: el motor congela la edad al iniciar y la lee
        // de ahí, y el modo estricto no admite cargas perezosas.
        $destinatarios = $asignacion->destinatarios()->with('persona')->get();

        foreach ($destinatarios as $indice => $destinatario) {
            $destinatario->setRelation('asignacion', $asignacion);

            $aplicacion = app(\App\Domain\Evaluaciones\Servicios\MotorAplicacion::class)
                ->iniciar($destinatario);

            \App\Domain\Evaluaciones\Modelos\Respuesta::query()->create([
                'aplicacion_id' => $aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opciones[$indice % $opciones->count()]->id,
                'uuid_cliente' => (string) \Illuminate\Support\Str::uuid(),
                'respondida_en' => now(),
            ]);

            $aplicacion->update(['estado' => 'completada', 'finalizada_en' => now()]);

            (new EtapaBrutos($aplicacion->id))->handle();
        }

        // `$escala` documenta contra qué se agrega; el pipeline la llena.
        $this->assertNotNull($escala);

        return $asignacion->refresh();
    }
}
