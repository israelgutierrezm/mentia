<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Consentimientos\Servicios\GestorArco;
use App\Domain\Interpretacion\Modelos\ReporteGenerado;
use App\Domain\Interpretacion\Servicios\GeneradorReportes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioCentinela;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Fugas cross-tenant en lo que construyeron las Fases 6 a 9.
 *
 * La suite de aislamiento de la Fase 1 cubre organización, personas y roles.
 * Esto cubre lo que vino después: resultados, reportes, alertas y ARCO — que es
 * donde vive el material clínico, y por tanto donde una fuga cuesta más.
 *
 * TODAS las respuestas esperadas son 404, no 403. Un 403 confirma que el
 * recurso existe, y un resultado que existe significa que esa persona fue
 * evaluada allí (Doc 07 §8.1).
 */
class FugaResultadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_no_se_ve_el_resultado_de_una_aplicacion_ajena(): void
    {
        $ajeno = $this->escenarioCalificadoEnOtroTenant();
        $intrusa = $this->intrusaCon(['resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->getJson('/api/v1/aplicaciones/'.$ajeno->aplicacion->uuid.'/resultados', $this->encabezado($intrusa))
            ->assertNotFound();
    }

    public function test_no_se_ve_el_perfil_longitudinal_de_una_persona_ajena(): void
    {
        $ajeno = $this->escenarioCalificadoEnOtroTenant();
        $intrusa = $this->intrusaCon(['resultados.ver_detalle', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->getJson(
                '/api/v1/personas/'.$ajeno->aplicacion->persona->uuid.'/perfil-longitudinal',
                $this->encabezado($intrusa),
            )
            ->assertNotFound();
    }

    public function test_no_se_genera_un_reporte_de_una_aplicacion_ajena(): void
    {
        Storage::fake('local');

        $ajeno = $this->escenarioCalificadoEnOtroTenant();
        $intrusa = $this->intrusaCon(['resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->postJson(
                '/api/v1/reportes/aplicaciones/'.$ajeno->aplicacion->uuid,
                [],
                $this->encabezado($intrusa),
            )
            ->assertNotFound();

        $this->assertSame(0, ReporteGenerado::query()->withoutGlobalScopes()->count());
    }

    public function test_no_se_descarga_un_reporte_de_otro_tenant(): void
    {
        Storage::fake('local');

        $ajeno = $this->escenarioCalificadoEnOtroTenant();
        $duenoTenant = $ajeno->tenant;

        $psicologa = $duenoTenant->persona();
        $duenoTenant->asignarRol($psicologa, $duenoTenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $duenoTenant->consentir($ajeno->aplicacion->persona);

        $reporte = app(GeneradorReportes::class)->individual($psicologa, $ajeno->aplicacion);

        $intrusa = $this->intrusaCon(['resultados.exportar', 'personas.ver']);

        /*
         * El PDF de un reporte es el documento clínico completo: si se filtra,
         * se filtró todo de una sola vez.
         */
        $this->comoIntrusa($intrusa)
            ->get('/api/v1/reportes/'.$reporte->uuid.'/descargar', $this->encabezado($intrusa))
            ->assertNotFound();
    }

    public function test_no_se_atiende_una_alerta_de_otro_tenant(): void
    {
        $duenoTenant = EscenarioTenant::nuevo()->activar();
        $alerta = (new EscenarioCentinela($duenoTenant))->dispararCentinela();

        $intrusa = $this->intrusaCon(['alertas.atender', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->postJson(
                '/api/v1/alertas/'.$alerta->id.'/atender',
                ['resolucion' => 'Se atendió conforme al protocolo interno de la organización.'],
                $this->encabezado($intrusa),
            )
            ->assertNotFound();

        $this->assertSame('nueva', $alerta->refresh()->estado);
    }

    public function test_el_listado_de_alertas_no_trae_las_de_otro_tenant(): void
    {
        $duenoTenant = EscenarioTenant::nuevo()->activar();
        (new EscenarioCentinela($duenoTenant))->dispararCentinela();

        $intrusa = $this->intrusaCon(['alertas.atender', 'personas.ver']);

        $respuesta = $this->comoIntrusa($intrusa)
            ->getJson('/api/v1/alertas', $this->encabezado($intrusa));

        $respuesta->assertOk()->assertJsonPath('total', 0);

        /*
         * La alerta EXISTE: lo que no existe es para esta organización. Hay que
         * saltarse el global scope hasta para contarla desde aquí —el propio
         * andamio de la prueba queda filtrado—, que es la demostración más
         * directa de que el aislamiento no depende de que alguien recuerde
         * poner un `where`.
         */
        $this->assertSame(1, Alerta::query()->withoutGlobalScopes()->count());
    }

    public function test_no_se_exporta_el_expediente_de_una_solicitud_arco_ajena(): void
    {
        $duenoTenant = EscenarioTenant::nuevo()->activar();
        $titular = $duenoTenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'acceso',
            'Solicito copia íntegra de mi expediente psicométrico.',
        );

        $intrusa = $this->intrusaCon(['arco.gestionar', 'personas.ver']);

        /*
         * Una exportación ARCO es el expediente completo de una persona en un
         * solo JSON. Es el endpoint más goloso del sistema.
         */
        $this->comoIntrusa($intrusa)
            ->getJson('/api/v1/arco/'.$solicitud->uuid.'/exportar', $this->encabezado($intrusa))
            ->assertNotFound();
    }

    public function test_el_listado_de_arco_no_trae_las_de_otro_tenant(): void
    {
        $duenoTenant = EscenarioTenant::nuevo()->activar();
        $titular = $duenoTenant->persona();

        app(GestorArco::class)->recibir($titular, $titular, 'acceso', 'Solicito mi expediente.');

        $intrusa = $this->intrusaCon(['arco.gestionar', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->getJson('/api/v1/arco', $this->encabezado($intrusa))
            ->assertOk()
            ->assertJsonCount(0, 'datos');
    }

    public function test_no_se_valida_el_borrador_de_ia_de_otro_tenant(): void
    {
        $ajeno = $this->escenarioCalificadoEnOtroTenant();
        $duenoTenant = $ajeno->tenant;

        $this->app->instance(
            \App\Domain\Interpretacion\Contratos\RedactaBorradores::class,
            new \Tests\Apoyo\RedactorFalso,
        );

        $reporte = app(\App\Domain\Interpretacion\Servicios\IntegradorReportes::class)->generar(
            $duenoTenant->persona(),
            $ajeno->aplicacion->persona,
            [$ajeno->aplicacion],
            $duenoTenant->organizacion->id,
        );

        $intrusa = $this->intrusaCon(['ia.validar_reportes', 'personas.ver']);

        $this->comoIntrusa($intrusa)
            ->postJson(
                '/api/v1/reportes/'.$reporte->uuid.'/validar',
                ['aprueba' => true],
                $this->encabezado($intrusa),
            )
            ->assertNotFound();

        $this->assertSame('borrador', $reporte->borradorIa?->refresh()->estado);
    }

    // ── Andamio ───────────────────────────────────────────────────────────

    private EscenarioTenant $tenantIntruso;

    private function escenarioCalificadoEnOtroTenant(): EscenarioCalificacion
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Dentro de lo esperado.', ['operador' => '>=', 'valor_min' => 0]);
        $escenario->contestar([2]);
        $escenario->calificar();

        return $escenario;
    }

    /**
     * Una intrusa con TODOS los permisos que hagan falta, en SU propia
     * organización.
     *
     * El punto de la prueba es que el permiso no alcanza: quien tiene
     * `resultados.ver_detalle` lo tiene en su tenant, no en el de junto. Una
     * intrusa sin permisos probaría el `can:` y no el aislamiento.
     *
     * @param  list<string>  $permisos
     */
    private function intrusaCon(array $permisos): User
    {
        $this->tenantIntruso = EscenarioTenant::nuevo()->activar();

        $persona = $this->tenantIntruso->persona();
        $this->tenantIntruso->asignarRol(
            $persona,
            $this->tenantIntruso->rol('Psicóloga', $permisos, 4),
        );

        return $this->tenantIntruso->usuarioDe($persona);
    }

    private function comoIntrusa(User $cuenta): self
    {
        return $this->actingAs($cuenta)
            ->withSession(['organizacion_id' => $this->tenantIntruso->organizacion->id]);
    }

    /**
     * @return array<string, string>
     */
    private function encabezado(User $cuenta): array
    {
        return ['X-Organizacion' => (string) $this->tenantIntruso->organizacion->id];
    }
}
