<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Doc 07 §6 — la entrega de resultados.
 *
 * Lo que se prueba aquí no es que el JSON tenga las llaves correctas: es que la
 * AUDIENCIA la decida el servidor y que los números no salgan para quien no
 * puede leerlos.
 */
class ApiResultadosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_profesional_recibe_puntajes_e_interpretacion_tecnica(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Perfil técnico: puntaje {puntaje}.', [
            'operador' => '>=',
            'valor_min' => 0,
            'audiencia' => 'profesional',
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $respuesta = $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson('/api/v1/aplicaciones/'.$escenario->aplicacion->uuid.'/resultados', [
                'X-Organizacion' => (string) $tenant->organizacion->id,
            ]);

        $respuesta->assertOk()
            ->assertJsonPath('audiencia', 'profesional')
            ->assertJsonStructure(['escalas', 'interpretaciones', 'validez_detalle']);
    }

    public function test_la_audiencia_no_se_puede_pedir_por_parametro(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([3]);
        $escenario->calificar();

        $titular = $escenario->aplicacion->persona;
        $tenant->consentir($titular);

        $respuesta = $this->actingAs($tenant->usuarioDe($titular))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson(
                '/api/v1/aplicaciones/'.$escenario->aplicacion->uuid.'/resultados?audiencia=profesional',
                ['X-Organizacion' => (string) $tenant->organizacion->id],
            );

        $respuesta->assertOk();

        /*
         * Si el evaluado pudiera pedir la audiencia `profesional`, el texto
         * cuidado que se escribió para él dejaría de servir para nada:
         * bastaría cambiar un parámetro en la URL para leer la versión técnica
         * de su propio tamizaje.
         */
        $this->assertNotSame('profesional', $respuesta->json('audiencia'));
    }

    public function test_sin_permiso_de_detalle_no_salen_los_puntajes(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Todo en orden.', [
            'operador' => '>=',
            'valor_min' => 0,
            'audiencia' => 'profesional',
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $orientador = $tenant->persona();
        $tenant->asignarRol($orientador, $tenant->rol('Orientador', [
            'resultados.ver_resumen', 'personas.ver',
        ], 4));
        $tenant->consentir($escenario->aplicacion->persona);

        $respuesta = $this->actingAs($tenant->usuarioDe($orientador))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson('/api/v1/aplicaciones/'.$escenario->aplicacion->uuid.'/resultados', [
                'X-Organizacion' => (string) $tenant->organizacion->id,
            ]);

        $respuesta->assertOk();

        /*
         * Un percentil sin nadie que lo explique es una cifra que la persona va
         * a interpretar sola, y casi siempre mal.
         */
        $respuesta->assertJsonMissingPath('escalas');
        $this->assertNotEmpty($respuesta->json('interpretaciones'));
    }

    public function test_un_resultado_ajeno_responde_404_y_no_403(): void
    {
        $tenantA = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenantA);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([3]);
        $escenario->calificar();

        $tenantB = EscenarioTenant::nuevo()->activar();
        $intrusa = $tenantB->persona();
        $tenantB->asignarRol($intrusa, $tenantB->rol('Psicóloga', [
            'resultados.ver_resumen', 'resultados.ver_detalle', 'personas.ver',
        ], 4));

        $respuesta = $this->actingAs($tenantB->usuarioDe($intrusa))
            ->withSession(['organizacion_id' => $tenantB->organizacion->id])
            ->getJson('/api/v1/aplicaciones/'.$escenario->aplicacion->uuid.'/resultados', [
                'X-Organizacion' => (string) $tenantB->organizacion->id,
            ]);

        /*
         * 404 y no 403: un 403 confirmaría que esa aplicación existe y que esa
         * persona fue evaluada aquí, que es justo lo que no se puede filtrar.
         */
        $respuesta->assertNotFound();
    }

    public function test_el_perfil_longitudinal_devuelve_la_serie_con_sus_cambios(): void
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

        $respuesta = $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson(
                '/api/v1/personas/'.$escenario->aplicacion->persona->uuid.'/perfil-longitudinal',
                ['X-Organizacion' => (string) $tenant->organizacion->id],
            );

        $respuesta->assertOk()
            ->assertJsonPath('series.0.constructo', 'ANS')
            ->assertJsonPath('series.0.puntos.0.valor', 65);
    }
}
