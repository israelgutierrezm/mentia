<?php

declare(strict_types=1);

namespace Tests\Feature\Personas;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Personas\Excepciones\TutoriaInvalida;
use App\Domain\Personas\Servicios\GestorTutorias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * El flujo completo: registrar no da acceso, acreditar sí, revocar lo corta.
 *
 * Es la compuerta más delicada del módulo: una tutoría vigente abre el
 * expediente psicológico de un menor a otra persona.
 */
class TutoriasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function gestor(): GestorTutorias
    {
        return app(GestorTutorias::class);
    }

    public function test_una_tutoria_recien_registrada_no_da_acceso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();

        $tutoria = $this->gestor()->registrar($madre, $menor, 'madre');

        $this->assertSame('pendiente_validacion', $tutoria->estado);

        $decision = app(AccesoService::class)->autorizar($madre, 'expediente.ver', $menor);

        $this->assertTrue(
            $decision->denegado(),
            'Declarar el parentesco no acredita nada: si diera acceso, cualquiera se '
            .'registraría como madre de un menor y se llevaría su expediente.'
        );
    }

    public function test_acreditarla_abre_el_acceso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();
        $psicologa = $escenario->persona();

        $tutoria = $this->gestor()->registrar($madre, $menor, 'madre');
        $this->gestor()->validar($tutoria, $psicologa);

        $this->assertSame('vigente', $tutoria->refresh()->estado);
        $this->assertSame($psicologa->id, $tutoria->validada_por);

        $decision = app(AccesoService::class)->autorizar($madre, 'expediente.ver', $menor);

        $this->assertTrue($decision->permitido, $decision->motivo);
    }

    public function test_nadie_valida_su_propia_tutoria(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $supuestoTutor = $escenario->persona();
        $menor = $escenario->persona();

        $tutoria = $this->gestor()->registrar($supuestoTutor, $menor, 'madre');

        $this->expectException(TutoriaInvalida::class);

        // Sin esta regla, el autorregistro sería una puerta abierta.
        $this->gestor()->validar($tutoria, $supuestoTutor);
    }

    public function test_revocarla_corta_el_acceso_de_inmediato(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();
        $psicologa = $escenario->persona();

        $tutoria = $this->gestor()->registrar($madre, $menor, 'madre');
        $this->gestor()->validar($tutoria, $psicologa);

        $accesos = app(AccesoService::class);
        $this->assertTrue($accesos->autorizar($madre, 'expediente.ver', $menor)->permitido);

        $this->gestor()->revocar($tutoria);

        $this->assertTrue(
            $accesos->autorizar($madre, 'expediente.ver', $menor)->denegado(),
            'La revocación surte efecto inmediato, no al día siguiente.'
        );
    }

    public function test_la_revocada_se_conserva_y_se_puede_volver_a_registrar(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();
        $psicologa = $escenario->persona();

        $tutoria = $this->gestor()->registrar($madre, $menor, 'madre');
        $this->gestor()->validar($tutoria, $psicologa);
        $this->gestor()->revocar($tutoria);

        // Re-registrar sobre la misma fila: crear otra chocaría contra el único
        // de (tutor, menor).
        $renovada = $this->gestor()->registrar($madre, $menor, 'madre');

        $this->assertSame($tutoria->id, $renovada->id);
        $this->assertSame('pendiente_validacion', $renovada->estado);
        $this->assertNull($renovada->validada_por);

        $this->assertSame(
            1,
            \App\Domain\Personas\Modelos\Tutoria::query()
                ->where('menor_persona_id', $menor->id)->count()
        );
    }

    public function test_una_tutoria_ya_vigente_no_se_vuelve_a_validar(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();
        $psicologa = $escenario->persona();

        $tutoria = $this->gestor()->registrar($madre, $menor, 'madre');
        $this->gestor()->validar($tutoria, $psicologa);

        $this->expectException(TutoriaInvalida::class);

        $this->gestor()->validar($tutoria, $psicologa);
    }

    public function test_nadie_es_tutor_de_si_mismo(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();

        $this->expectException(TutoriaInvalida::class);

        $this->gestor()->registrar($persona, $persona, 'otro');
    }

    public function test_no_se_acredita_una_tutoria_sobre_alguien_de_otro_tenant(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $menorDeB = $b->persona();

        $a->activar();
        $tutorDeA = $a->persona();

        $this->expectException(TutoriaInvalida::class);

        // `tutorias` es global; el acotamiento se hace sobre el MENOR.
        $this->gestor()->registrar($tutorDeA, $menorDeB, 'madre');
    }

    public function test_el_listado_solo_muestra_tutorias_de_menores_del_tenant(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $tutoraDeB = $b->persona();
        $menorDeB = $b->persona();
        $this->gestor()->registrar($tutoraDeB, $menorDeB, 'madre');

        $a->activar();
        $tutoraDeA = $a->persona();
        $menorDeA = $a->persona();
        $this->gestor()->registrar($tutoraDeA, $menorDeA, 'padre');

        $listado = $this->gestor()->listar();

        $this->assertCount(1, $listado);
        $this->assertSame($menorDeA->id, $listado->first()?->menor_persona_id);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────

    public function test_la_pantalla_exige_el_permiso_de_validar(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $escenario->asignarRol($persona, $escenario->rol('Docente', ['personas.ver'], 1));

        $respuesta = $this->actingAs($escenario->usuarioDe($persona))
            ->withSession(['organizacion_id' => $escenario->organizacion->id])
            ->get('/tutorias');

        $respuesta->assertForbidden();
    }

    public function test_el_flujo_completo_por_la_api(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $psicologa = $escenario->persona();
        $escenario->asignarRol(
            $psicologa,
            $escenario->rol('Psicóloga', ['tutorias.validar', 'personas.ver'], 4)
        );
        $usuario = $escenario->usuarioDe($psicologa);

        $madre = $escenario->persona();
        $menor = $escenario->persona();

        $peticion = $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Organizacion', (string) $escenario->organizacion->id);

        $alta = $peticion->postJson('/api/v1/tutorias', [
            'tutor_uuid' => $madre->uuid,
            'menor_uuid' => $menor->uuid,
            'parentesco' => 'madre',
        ]);

        $alta->assertCreated();
        $alta->assertJsonPath('estado', 'pendiente_validacion');

        $id = $alta->json('id');

        $validacion = $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Organizacion', (string) $escenario->organizacion->id)
            ->postJson('/api/v1/tutorias/'.$id.'/validar');

        $validacion->assertOk();
        $validacion->assertJsonPath('estado', 'vigente');

        $this->assertTrue(
            app(AccesoService::class)->autorizar($madre, 'expediente.ver', $menor)->permitido
        );
    }
}
