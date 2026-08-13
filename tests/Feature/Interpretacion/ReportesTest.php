<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use App\Domain\Interpretacion\Contratos\RedactaBorradores;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Servicios\ArmadorInsumoIA;
use App\Domain\Interpretacion\Servicios\GeneradorReportes;
use App\Domain\Interpretacion\Servicios\IntegradorReportes;
use App\Domain\Interpretacion\Servicios\RenderizadorPlantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\Apoyo\RedactorFalso;
use Tests\TestCase;

/**
 * Reportes, PDF y el integrador con IA (Doc 05 §5 y §6).
 */
class ReportesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ── El renderizador de plantillas ─────────────────────────────────────

    public function test_el_renderizador_escapa_lo_que_sustituye(): void
    {
        $render = app(RenderizadorPlantilla::class)->render(
            '<p>{{ nombre }}</p>',
            ['nombre' => '<script>alert(1)</script>'],
        );

        /*
         * Un nombre con `<script>` dentro llega al HTML del reporte, y el
         * reporte se abre en un navegador. No hay marcador "sin escapar" a
         * propósito: en cuanto exista, alguien lo va a usar.
         */
        $this->assertStringNotContainsString('<script>', $render);
        $this->assertStringContainsString('&lt;script&gt;', $render);
    }

    public function test_el_renderizador_repite_bloques(): void
    {
        $render = app(RenderizadorPlantilla::class)->render(
            '{{#escalas}}<li>{{ nombre }}: {{ valor }}</li>{{/escalas}}',
            ['escalas' => [
                ['nombre' => 'Ansiedad', 'valor' => 70],
                ['nombre' => 'Depresión', 'valor' => 40],
            ]],
        );

        $this->assertStringContainsString('<li>Ansiedad: 70</li>', $render);
        $this->assertStringContainsString('<li>Depresión: 40</li>', $render);
    }

    public function test_el_renderizador_no_compila_codigo(): void
    {
        $render = app(RenderizadorPlantilla::class)->render(
            '<p>{{ php_uname() }}</p><p>@php echo 1; @endphp</p>',
            [],
        );

        /*
         * Una plantilla que un tenant puede editar y que el servidor compilara
         * es ejecución de código arbitrario con los pasos intermedios ya
         * hechos: quien editara la plantilla podría leer el `.env`.
         */
        $this->assertStringContainsString('{{ php_uname() }}', $render);
        $this->assertStringContainsString('@php echo 1; @endphp', $render);
    }

    // ── El reporte individual ─────────────────────────────────────────────

    public function test_el_reporte_individual_genera_un_pdf_y_lo_guarda(): void
    {
        Storage::fake('local');

        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $reporte = app(GeneradorReportes::class)->individual($psicologa, $escenario->aplicacion);

        $this->assertSame('profesional', $reporte->audiencia);

        /*
         * El PDF se GUARDA, no se regenera. Un reporte es un documento
         * entregado: si el catálogo cambia mañana, el papel que alguien tiene
         * en la mano tiene que seguir explicándose.
         */
        Storage::disk('local')->assertExists(app(GeneradorReportes::class)->rutaDe($reporte));
    }

    public function test_descargar_deja_bitacora(): void
    {
        Storage::fake('local');

        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $reporte = app(GeneradorReportes::class)->individual($psicologa, $escenario->aplicacion);

        app(GeneradorReportes::class)->contenidoPdf($reporte, $psicologa);

        // Quién se llevó qué resultado y cuándo es justo lo que la LFPDPPP
        // obliga a poder demostrar.
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'reporte.descargado',
            'recurso_id' => $reporte->id,
        ]);
    }

    // ── El integrador con IA ──────────────────────────────────────────────

    public function test_el_insumo_de_la_ia_va_pseudonimizado(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $falso = new RedactorFalso;
        $this->app->instance(RedactaBorradores::class, $falso);

        $titular = $escenario->aplicacion->persona;
        $psicologa = $tenant->persona();

        app(IntegradorReportes::class)->generar(
            $psicologa,
            $titular,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $serializado = (string) json_encode($falso->ultimoInsumo);

        /*
         * Ni nombre, ni CURP, ni fecha de nacimiento. Una respuesta abierta
         * puede contener cualquier cosa que la persona haya querido escribir, y
         * en un tamizaje clínico eso incluye lo más delicado del expediente.
         */
        $this->assertStringNotContainsString($titular->nombres, $serializado);
        $this->assertStringNotContainsString((string) $titular->curp, $serializado);
        $this->assertStringNotContainsString($titular->fecha_nacimiento->toDateString(), $serializado);

        // Lo que sí va: escalas, normas e interpretaciones ya resueltas.
        $this->assertArrayHasKey('instrumentos', $falso->ultimoInsumo);
    }

    public function test_el_borrador_nace_como_borrador(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $this->app->instance(RedactaBorradores::class, new RedactorFalso);

        $reporte = app(IntegradorReportes::class)->generar(
            $tenant->persona(),
            $escenario->aplicacion->persona,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $this->assertSame('borrador', $reporte->borradorIa?->estado);
        $this->assertFalse($reporte->estaFirmado());
    }

    public function test_no_se_firma_un_reporte_con_borrador_sin_validar(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $this->app->instance(RedactaBorradores::class, new RedactorFalso);

        $reporte = app(IntegradorReportes::class)->generar(
            $tenant->persona(),
            $escenario->aplicacion->persona,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $this->expectException(ReporteNoGenerable::class);
        $this->expectExceptionMessageMatches('/nadie validó/');

        /*
         * La firma dice "yo respondo por esto". Firmar texto que redactó una IA
         * y que nadie leyó es exactamente lo que el Doc 05 §6 prohíbe.
         */
        app(GeneradorReportes::class)->firmar($reporte, $tenant->persona());
    }

    public function test_validar_exige_el_permiso(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $this->app->instance(RedactaBorradores::class, new RedactorFalso);

        $reporte = app(IntegradorReportes::class)->generar(
            $tenant->persona(),
            $escenario->aplicacion->persona,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $sinPermiso = $tenant->persona();
        $tenant->asignarRol($sinPermiso, $tenant->rol('Auxiliar', ['personas.ver'], 1));

        $this->expectExceptionMessageMatches('/permiso para validar/');

        app(IntegradorReportes::class)->validar($reporte->borradorIa, $sinPermiso, true);
    }

    public function test_rechazar_un_borrador_exige_decir_por_que(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $this->app->instance(RedactaBorradores::class, new RedactorFalso);

        $reporte = app(IntegradorReportes::class)->generar(
            $tenant->persona(),
            $escenario->aplicacion->persona,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $validadora = $tenant->persona();
        $tenant->asignarRol($validadora, $tenant->rol('Directora', ['ia.validar_reportes'], 4));

        $this->expectExceptionMessageMatches('/exige decir por qué/');

        /*
         * Rechazar sin decir por qué deja el expediente con un borrador muerto
         * y sin explicación. Quien lo lea en seis meses tiene que poder saber
         * si se rechazó porque el texto estaba mal o porque los datos lo
         * estaban.
         */
        app(IntegradorReportes::class)->validar($reporte->borradorIa, $validadora, false);
    }

    public function test_validado_y_corregido_se_puede_firmar(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        $this->app->instance(RedactaBorradores::class, new RedactorFalso);

        $reporte = app(IntegradorReportes::class)->generar(
            $tenant->persona(),
            $escenario->aplicacion->persona,
            [$escenario->aplicacion],
            $tenant->organizacion->id,
        );

        $validadora = $tenant->persona();
        $tenant->asignarRol($validadora, $tenant->rol('Directora', ['ia.validar_reportes'], 4));

        // Quien valida no aprueba a ciegas: EDITA. Es la diferencia entre
        // revisar y sellar.
        app(IntegradorReportes::class)->validar(
            $reporte->borradorIa,
            $validadora,
            true,
            'Texto corregido por la profesional.',
        );

        $firmado = app(GeneradorReportes::class)->firmar($reporte->refresh(), $validadora);

        $this->assertTrue($firmado->estaFirmado());
        $this->assertSame(
            'Texto corregido por la profesional.',
            $firmado->borradorIa?->refresh()->borrador,
        );
    }

    public function test_el_hash_del_insumo_cambia_si_cambian_los_datos(): void
    {
        $armador = app(ArmadorInsumoIA::class);

        $uno = $armador->hashDe(['a' => 1]);
        $otro = $armador->hashDe(['a' => 2]);

        // Sirve para detectar que alguien recalificó entre el borrador y la
        // firma, sin guardar una segunda copia del material clínico.
        $this->assertNotSame($uno, $otro);
        $this->assertSame($uno, $armador->hashDe(['a' => 1]));
    }

    // ── Andamio ───────────────────────────────────────────────────────────

    private function escenarioCalificado(): EscenarioCalificacion
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Nivel dentro de lo esperado.', [
            'operador' => '>=',
            'valor_min' => 0,
            'audiencia' => 'profesional',
        ]);

        $escenario->contestar([2]);
        $escenario->calificar();

        return $escenario;
    }

    public function test_sin_plantilla_no_se_inventa_un_reporte(): void
    {
        $escenario = $this->escenarioCalificado();
        $tenant = $escenario->tenant;

        \App\Domain\Interpretacion\Modelos\PlantillaReporte::query()->delete();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $this->expectException(ReporteNoGenerable::class);

        app(GeneradorReportes::class)->individual($psicologa, $escenario->aplicacion);

        $this->assertSame(0, ReporteGenerado::query()->count());
    }
}
