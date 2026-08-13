<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluaciones;

use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Los endpoints del Doc 07 §4, con el foco en lo que protegen.
 */
class AsignacionesApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * @param  list<string>  $permisos
     */
    private function actor(EscenarioTenant $tenant, array $permisos, int $nivel = 2): User
    {
        $persona = $tenant->persona();
        $tenant->asignarRol($persona, $tenant->rol('Rol '.uniqid(), $permisos, $nivel));

        return $tenant->usuarioDe($persona);
    }

    private function comoApi(User $usuario, EscenarioTenant $tenant): static
    {
        return $this->actingAs($usuario, 'sanctum')
            ->withHeader('X-Organizacion', (string) $tenant->organizacion->id);
    }

    public function test_se_crea_una_asignacion_individual(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $actor = $this->actor($tenant, ['evaluaciones.asignar']);
        $sujeto = $tenant->persona();

        $respuesta = $this->comoApi($actor, $tenant)->postJson('/api/v1/asignaciones', [
            'proposito_id' => $escenario->proposito->id,
            'origen_tipo' => 'individual',
            'destinatarios' => [$sujeto->uuid],
        ]);

        $respuesta->assertCreated();
        $respuesta->assertJsonPath('destinatarios', 1);
        $respuesta->assertJsonStructure(['folio']);
    }

    public function test_asignar_de_forma_discreta_exige_su_propio_permiso(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        // Puede asignar, pero NO de forma discreta.
        $actor = $this->actor($tenant, ['evaluaciones.asignar']);

        $respuesta = $this->comoApi($actor, $tenant)->postJson('/api/v1/asignaciones', [
            'proposito_id' => $escenario->proposito->id,
            'origen_tipo' => 'individual',
            'destinatarios' => [$tenant->persona()->uuid],
            'es_discreta' => true,
        ]);

        $respuesta->assertForbidden();
    }

    public function test_una_discreta_ajena_responde_404_no_403(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $psicologa = $tenant->persona();
        $discreta = $escenario->individual($psicologa, [$tenant->persona()], esDiscreta: true);

        $coordinador = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 2);

        $respuesta = $this->comoApi($coordinador, $tenant)
            ->getJson('/api/v1/asignaciones/'.$discreta->folio);

        /*
         * 404 y no 403: un 403 confirmaría que ese folio existe, y la
         * existencia de la asignación es justo lo que la discreción protege.
         */
        $respuesta->assertNotFound();
    }

    public function test_el_listado_no_incluye_discretas_ajenas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $discreta = $escenario->individual($tenant->persona(), [$tenant->persona()], esDiscreta: true);
        $normal = $escenario->individual($tenant->persona(), [$tenant->persona()]);

        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 2);

        $respuesta = $this->comoApi($actor, $tenant)->getJson('/api/v1/asignaciones');

        $respuesta->assertOk();

        $folios = array_column($respuesta->json('data'), 'folio');

        $this->assertContains($normal->folio, $folios);
        $this->assertNotContains($discreta->folio, $folios);
    }

    public function test_los_destinatarios_de_una_anonima_no_se_listan(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $anonima = $escenario->individual($tenant->persona(), [$persona], esAnonima: true);

        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 4);

        $respuesta = $this->comoApi($actor, $tenant)
            ->getJson('/api/v1/asignaciones/'.$anonima->folio.'/destinatarios');

        $respuesta->assertStatus(409);

        // Ni el uuid ni el nombre de nadie salen. Lo que sí llega es el avance
        // agregado, que es lo único que se puede saber de una anónima.
        $respuesta->assertDontSee($persona->uuid);
        $respuesta->assertJsonStructure(['error', 'avance' => ['total', 'completadas']]);
    }

    public function test_el_avance_de_una_no_anonima_si_lista_destinatarios(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 4);

        $respuesta = $this->comoApi($actor, $tenant)
            ->getJson('/api/v1/asignaciones/'.$asignacion->folio.'/destinatarios');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('data.0.persona_uuid', $persona->uuid);
    }

    public function test_exentar_por_la_api_exige_motivo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $destinatario = $asignacion->destinatarios()->first();

        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 4);

        $sinMotivo = $this->comoApi($actor, $tenant)->postJson(
            '/api/v1/asignaciones/'.$asignacion->folio.'/destinatarios/'.$destinatario->id.'/exentar',
            []
        );

        $sinMotivo->assertStatus(422);

        $conMotivo = $this->comoApi($actor, $tenant)->postJson(
            '/api/v1/asignaciones/'.$asignacion->folio.'/destinatarios/'.$destinatario->id.'/exentar',
            ['motivo' => 'Incapacidad médica durante toda la ventana.']
        );

        $conMotivo->assertOk();
        $conMotivo->assertJsonPath('estado', 'exenta');
    }

    public function test_no_se_exenta_a_un_destinatario_de_otra_asignacion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $primera = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $segunda = $escenario->individual($tenant->persona(), [$tenant->persona()]);

        $ajeno = $segunda->destinatarios()->first();
        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 4);

        $respuesta = $this->comoApi($actor, $tenant)->postJson(
            '/api/v1/asignaciones/'.$primera->folio.'/destinatarios/'.$ajeno->id.'/exentar',
            ['motivo' => 'Intento de exentar a alguien de otra asignación.']
        );

        $respuesta->assertNotFound();

        // Lo que importa no es en qué estado quedó, sino que NO quedó exento:
        // nace en `consentimiento_pendiente` porque la asignación lo exige.
        $this->assertNotSame('exenta', $ajeno->refresh()->estado);
        $this->assertNull($ajeno->motivo_exencion);
    }

    public function test_cerrar_por_la_api_cambia_el_estado(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $actor = $this->actor($tenant, ['evaluaciones.asignar'], nivel: 4);

        $respuesta = $this->comoApi($actor, $tenant)
            ->postJson('/api/v1/asignaciones/'.$asignacion->folio.'/cerrar');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('estado', 'cerrada');
    }

    public function test_una_asignacion_de_otro_tenant_no_se_alcanza(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $escenarioB = new EscenarioAsignacion($b);
        $deB = $escenarioB->individual($b->persona(), [$b->persona()]);

        $a->activar();
        $actor = $this->actor($a, ['evaluaciones.asignar'], nivel: 4);

        $respuesta = $this->comoApi($actor, $a)
            ->getJson('/api/v1/asignaciones/'.$deB->folio);

        $respuesta->assertNotFound();
    }

    public function test_no_se_asigna_a_una_persona_de_otro_tenant(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $personaDeB = $b->persona();

        $a->activar();
        $escenario = new EscenarioAsignacion($a);
        $actor = $this->actor($a, ['evaluaciones.asignar']);

        $respuesta = $this->comoApi($actor, $a)->postJson('/api/v1/asignaciones', [
            'proposito_id' => $escenario->proposito->id,
            'origen_tipo' => 'individual',
            'destinatarios' => [$personaDeB->uuid],
        ]);

        /*
         * `personas` es global. Sin la comprobación de vinculación en
         * CreadorAsignaciones, mandar el uuid de cualquiera la metería como
         * destinataria de este tenant.
         */
        $respuesta->assertStatus(422);

        $this->assertSame(
            0,
            Persona::query()->where('id', $personaDeB->id)
                ->whereHas('vinculaciones', fn ($c) => $c->where('organizacion_id', $a->organizacion->id))
                ->count()
        );
    }
}
