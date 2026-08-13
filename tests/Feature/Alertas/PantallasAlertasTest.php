<?php

declare(strict_types=1);

namespace Tests\Feature\Alertas;

use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Alertas\Servicios\ProtocoloDeActuacion;
use App\Domain\Evaluaciones\Servicios\MotorAplicacion;
use App\Domain\Organizaciones\Modelos\OrganizacionConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioCentinela;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * El centro de alertas, las vistas de resultados y el mensaje de cierre.
 */
class PantallasAlertasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_centro_de_alertas_exige_su_permiso(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $docente = $tenant->persona();
        $tenant->asignarRol($docente, $tenant->rol('Docente', ['personas.ver'], 1));

        $this->actingAs($tenant->usuarioDe($docente))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/alertas')
            ->assertForbidden();
    }

    public function test_el_centro_lista_las_abiertas_con_las_criticas_arriba(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);
        $escenario->dispararCentinela();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/alertas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Alertas/Centro')
                ->where('conteos.criticas_abiertas', 1)
                ->has('alertas', 1));
    }

    public function test_cerrar_una_alerta_por_la_pantalla_exige_resolucion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);
        $alerta = $escenario->dispararCentinela();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->post('/alertas/'.$alerta->id.'/atender', ['resolucion' => 'ok'])
            ->assertSessionHasErrors('resolucion');

        $this->assertSame('nueva', $alerta->refresh()->estado);
    }

    public function test_una_alerta_de_otra_organizacion_responde_404(): void
    {
        $tenantA = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenantA);
        $alerta = $escenario->dispararCentinela();

        $tenantB = EscenarioTenant::nuevo()->activar();
        $intrusa = $tenantB->persona();
        $tenantB->asignarRol($intrusa, $tenantB->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        /*
         * 404 y no 403: un 403 confirmaría que esa alerta existe, y una alerta
         * que existe significa que alguien de esa organización dio positivo en
         * algo.
         */
        $this->actingAs($tenantB->usuarioDe($intrusa))
            ->withSession(['organizacion_id' => $tenantB->organizacion->id])
            ->post('/alertas/'.$alerta->id.'/atender', [
                'resolucion' => 'Se atendió conforme al protocolo interno de la organización.',
            ])
            ->assertNotFound();
    }

    public function test_la_api_de_alertas_lista_y_cierra(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);
        $alerta = $escenario->dispararCentinela();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        $sesion = $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id]);

        $sesion->getJson('/api/v1/alertas', [
            'X-Organizacion' => (string) $tenant->organizacion->id,
        ])->assertOk()->assertJsonPath('total', 1);

        $sesion->postJson(
            '/api/v1/alertas/'.$alerta->id.'/atender',
            ['resolucion' => 'Se contactó a la persona y se canalizó al servicio de salud mental.'],
            ['X-Organizacion' => (string) $tenant->organizacion->id],
        )->assertOk()->assertJsonPath('estado', 'cerrada');
    }

    // ── Las vistas de resultados ──────────────────────────────────────────

    public function test_el_perfil_longitudinal_agrupa_por_dominio(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('ANS');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->baremo('ANS', [[0, 9, 65, 'medio']]);
        $escenario->contestar([3]);
        $escenario->calificar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/personas/'.$escenario->aplicacion->persona->uuid.'/perfil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Resultados/Longitudinal')
                ->has('dominios', 1)
                ->where('dominios.0.constructos.0.constructo', 'ANS'));
    }

    public function test_el_resultado_individual_se_dibuja_por_audiencia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Perfil dentro de lo esperado.', [
            'operador' => '>=',
            'valor_min' => 0,
            'audiencia' => 'profesional',
        ]);

        $escenario->contestar([2]);
        $escenario->calificar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/aplicaciones/'.$escenario->aplicacion->uuid.'/resultados')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Resultados/Individual')
                ->where('resultado.audiencia', 'profesional')
                ->has('resultado.escalas'));
    }

    // ── El mensaje de cierre ──────────────────────────────────────────────

    public function test_un_instrumento_sensible_muestra_los_recursos_de_apoyo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        OrganizacionConfiguracion::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'clave' => ProtocoloDeActuacion::CLAVE_RECURSOS,
            'valor' => 'Línea de la Vida: 800 911 2000, las 24 horas.',
        ]);

        // El instrumento sintético nace en sensibilidad 1: se sube a 4 para
        // que sea uno de los que exigen mensaje cuidado (Doc 05 §3).
        $escenario->dispararCentinela();

        $aplicacion = $escenario->aplicacion;
        $aplicacion->version->instrumento->update([
            'nivel_sensibilidad_id' => \App\Domain\Accesos\Modelos\NivelSensibilidad::query()
                ->where('nivel', 4)->value('id'),
        ]);

        $estructura = app(MotorAplicacion::class)->estructura($aplicacion->fresh());

        $this->assertStringContainsString('800 911 2000', (string) $estructura['recursos_apoyo']);
    }

    public function test_un_instrumento_poco_sensible_no_los_muestra(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        OrganizacionConfiguracion::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'clave' => ProtocoloDeActuacion::CLAVE_RECURSOS,
            'valor' => 'Línea de la Vida: 800 911 2000.',
        ]);

        $escenario->dispararCentinela();

        /*
         * Un test vocacional no cierra con una línea de crisis. El mensaje
         * cuidado existe para instrumentos de sensibilidad 3–4; ponerlo en
         * todos lo convertiría en decorado que nadie lee.
         */
        $estructura = app(MotorAplicacion::class)->estructura($escenario->aplicacion);

        $this->assertNull($estructura['recursos_apoyo']);
    }

    public function test_una_alerta_tiene_su_conteo_en_cero_cuando_no_hay_nada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/alertas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('conteos.abiertas', 0));

        $this->assertSame(0, Alerta::query()->count());
    }
}
