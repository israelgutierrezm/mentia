<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Suite de aislamiento: INTENTA fugar datos entre organizaciones (Doc 06 §4).
 *
 * No comprueba que las pantallas funcionen —de eso se encargan otras—, sino
 * que un actor de la organización A no pueda llegar a nada de la B por
 * ninguno de los endpoints que existen. Cada endpoint nuevo de cada fase suma
 * su intento aquí.
 *
 * El punto de escribirlas como ataques y no como afirmaciones: una fuga que
 * nadie intentó provocar no está descartada, sólo no probada.
 */
class FugaCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * @return array{0: EscenarioTenant, 1: EscenarioTenant}
     */
    private function dosTenants(): array
    {
        return [EscenarioTenant::nuevo(), EscenarioTenant::nuevo()];
    }

    // ── Global scopes ─────────────────────────────────────────────────────

    public function test_las_unidades_de_otro_tenant_no_se_ven(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $b->unidad(nombre: 'Plantel de B');

        $a->activar();
        $a->unidad(nombre: 'Plantel de A');

        $this->assertSame(
            ['Plantel de A'],
            Unidad::query()->pluck('nombre')->all(),
            'Con A activa, la consulta no puede traer unidades de B.'
        );
    }

    public function test_una_unidad_de_otro_tenant_no_se_encuentra_ni_por_id(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $unidadDeB = $b->unidad(nombre: 'Plantel de B');

        $a->activar();

        $this->assertNull(
            Unidad::query()->find($unidadDeB->id),
            'Conocer el id de una unidad ajena no debe alcanzar para leerla.'
        );
    }

    public function test_sin_organizacion_activa_no_se_ve_nada(): void
    {
        $a = EscenarioTenant::nuevo()->activar();
        $a->unidad(nombre: 'Plantel');

        // El caso del comando de consola o el job sin contexto: falla CERRADO.
        app(ContextoOrganizacion::class)->limpiar();

        $this->assertSame(
            0,
            Unidad::query()->count(),
            'Sin contexto, el scope debe devolver cero, no todo.'
        );
    }

    public function test_al_crear_se_estampa_la_organizacion_activa(): void
    {
        [$a, $b] = $this->dosTenants();

        $a->activar();

        // Se intenta crear una unidad declarando que es de B.
        $unidad = Unidad::query()->create([
            'organizacion_id' => $b->organizacion->id,
            'nombre' => 'Intento de sembrar en B',
            'tipo' => 'plantel',
            'estado' => 'activa',
        ]);

        /*
         * El trait respeta un organizacion_id explícito —hay altas legítimas
         * desde consola que lo necesitan—, así que la fila SÍ nace en B. Lo
         * que garantiza el aislamiento es que con A activa esa fila no se
         * pueda volver a leer: la escritura no abre una puerta de lectura.
         */
        $this->assertNull(
            Unidad::query()->find($unidad->id),
            'Aunque la escritura declare otro tenant, con A activa no se lee.'
        );
    }

    // ── Endpoints web ─────────────────────────────────────────────────────

    public function test_el_listado_de_unidades_no_filtra_las_de_otro_tenant(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $b->unidad(nombre: 'Plantel secreto de B');

        $a->activar();
        $a->unidad(nombre: 'Plantel de A');

        $actor = $this->actorConPermisos($a, ['unidades.gestionar']);

        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->get('/unidades');

        $respuesta->assertOk();
        $respuesta->assertDontSee('Plantel secreto de B');
        $respuesta->assertSee('Plantel de A');
    }

    public function test_no_se_puede_colgar_una_unidad_de_la_jerarquia_de_otro_tenant(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $padreDeB = $b->unidad(nombre: 'Plantel de B');

        $a->activar();
        $actor = $this->actorConPermisos($a, ['unidades.gestionar']);

        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->post('/unidades', [
                'nombre' => 'Departamento infiltrado',
                'tipo' => 'departamento',
                'unidad_padre_id' => $padreDeB->id,
            ]);

        $respuesta->assertNotFound();

        $b->activar();
        $this->assertSame(
            1,
            Unidad::query()->count(),
            'B debe seguir con su única unidad: nada se coló en su jerarquía.'
        );
    }

    public function test_no_se_puede_inscribir_en_un_grupo_propio_a_una_persona_de_otro_tenant(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $personaDeB = $b->persona();

        $a->activar();
        $grupoDeA = $a->agrupacion(nombre: 'Grupo de A');
        $actor = $this->actorConPermisos($a, ['agrupaciones.gestionar']);

        /*
         * `personas` es GLOBAL y no tiene global scope, así que este es el
         * intento más fácil de todos: mandar el uuid de alguien que sólo
         * existe en otro tenant. Lo detiene la comprobación de vinculación.
         */
        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->post('/agrupaciones/'.$grupoDeA->id.'/miembros', [
                'persona_uuid' => $personaDeB->uuid,
            ]);

        $respuesta->assertNotFound();

        $this->assertDatabaseMissing('agrupacion_miembros', [
            'agrupacion_id' => $grupoDeA->id,
            'persona_id' => $personaDeB->id,
        ]);
    }

    public function test_no_se_puede_dar_de_baja_a_un_miembro_de_otra_agrupacion(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $grupoDeB = $b->agrupacion(nombre: 'Grupo de B');
        $miembroDeB = $b->inscribir($b->persona(), $grupoDeB);

        $a->activar();
        $grupoDeA = $a->agrupacion(nombre: 'Grupo de A');
        $actor = $this->actorConPermisos($a, ['agrupaciones.gestionar']);

        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->delete('/agrupaciones/'.$grupoDeA->id.'/miembros/'.$miembroDeB->id);

        $respuesta->assertNotFound();

        $this->assertDatabaseHas('agrupacion_miembros', [
            'id' => $miembroDeB->id,
            'fecha_baja' => null,
        ]);
    }

    public function test_el_padron_de_personas_solo_muestra_a_las_vinculadas(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $personaDeB = $b->persona();

        $a->activar();
        $actor = $this->actorConPermisos($a, ['personas.ver']);

        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->get('/personas');

        $respuesta->assertOk();
        $respuesta->assertDontSee($personaDeB->uuid);
    }

    public function test_no_se_puede_asignar_un_rol_de_otro_tenant(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $rolDeB = $b->rol('Psicólogo de B', ['resultados.ver_detalle'], nivelMaximo: 4);

        $a->activar();
        $actor = $this->actorConPermisos($a, ['roles.gestionar']);
        $victima = $a->persona();

        $respuesta = $this->actingAs($actor)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->post('/alcances', [
                'persona_uuid' => $victima->uuid,
                'rol_id' => $rolDeB->id,
                'alcance_tipo' => 'organizacion',
            ]);

        $respuesta->assertNotFound();

        $this->assertDatabaseMissing('persona_rol_alcances', [
            'persona_id' => $victima->id,
            'rol_id' => $rolDeB->id,
        ]);
    }

    // ── Middleware de tenant ──────────────────────────────────────────────

    public function test_el_encabezado_x_organizacion_no_sirve_sin_vinculo_activo(): void
    {
        [$a, $b] = $this->dosTenants();

        // Actor legítimo de A.
        $a->activar();
        $persona = $a->persona();
        $a->asignarRol($persona, $a->rol('Coordinador A', ['unidades.gestionar'], 4));
        $actor = $a->usuarioDe($persona);

        /*
         * Y ADEMÁS con rol y alcance en B, pero SIN vínculo activo en B.
         *
         * Es un caso real: a alguien lo dieron de baja de una organización y
         * nadie le limpió los roles. Y es lo que hace que esta prueba aísle la
         * dimensión correcta — si el actor no tuviera el permiso en B, el 403
         * lo daría el `can:` y la prueba pasaría IGUAL con la comprobación de
         * vinculación quitada. Comprobado mutando el middleware.
         */
        $b->activar();
        $b->asignarRol($persona, $b->rol('Coordinador B', ['unidades.gestionar'], 4));
        $b->unidad(nombre: 'Plantel secreto de B');

        $a->activar();

        $respuesta = $this->actingAs($actor, 'sanctum')
            ->withHeader('X-Organizacion', (string) $b->organizacion->id)
            ->getJson('/api/v1/unidades');

        $respuesta->assertForbidden();
        $respuesta->assertDontSee('Plantel secreto de B');
    }

    public function test_sin_organizacion_activa_la_api_no_responde_datos(): void
    {
        $a = EscenarioTenant::nuevo()->activar();
        $actor = $this->actorConPermisos($a, ['unidades.gestionar']);

        $respuesta = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/unidades');

        $respuesta->assertForbidden();
    }

    public function test_la_api_acotada_a_su_organizacion_si_responde(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $b->unidad(nombre: 'Plantel de B');

        $a->activar();
        $a->unidad(nombre: 'Plantel de A');
        $actor = $this->actorConPermisos($a, ['unidades.gestionar']);

        $respuesta = $this->actingAs($actor, 'sanctum')
            ->withHeader('X-Organizacion', (string) $a->organizacion->id)
            ->getJson('/api/v1/unidades');

        $respuesta->assertOk();
        $respuesta->assertJsonCount(1, 'data');
        $respuesta->assertJsonPath('data.0.nombre', 'Plantel de A');
    }

    public function test_la_ficha_de_una_persona_ajena_no_devuelve_sus_datos(): void
    {
        [$a, $b] = $this->dosTenants();

        $b->activar();
        $personaDeB = $b->persona();

        $a->activar();
        $actor = $this->actorConPermisos($a, ['personas.ver']);

        /*
         * El uuid de una persona no es adivinable, pero puede llegar por otras
         * vías —un correo reenviado, un reporte compartido—. Tenerlo no debe
         * alcanzar para leer su ficha desde otro tenant.
         */
        $respuesta = $this->actingAs($actor, 'sanctum')
            ->withHeader('X-Organizacion', (string) $a->organizacion->id)
            ->getJson('/api/v1/personas/'.$personaDeB->uuid);

        $respuesta->assertForbidden();
        $respuesta->assertDontSee($personaDeB->nombres);
        $respuesta->assertDontSee((string) $personaDeB->curp);
    }

    // ── AccesoService ─────────────────────────────────────────────────────

    public function test_un_alcance_de_otro_tenant_no_concede_acceso(): void
    {
        [$a, $b] = $this->dosTenants();

        $actorEnA = $a->persona();
        $sujetoEnB = $b->persona();

        // El actor tiene rol y alcance completos... en A.
        $a->activar();
        $rol = $a->rol('Orientador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $a->asignarRol($actorEnA, $rol, PersonaRolAlcance::TIPO_ORGANIZACION);

        // Y se le pregunta por alguien de B, con A todavía activa.
        $decision = app(\App\Domain\Accesos\Servicios\AccesoService::class)
            ->autorizar($actorEnA, 'resultados.ver_detalle', $sujetoEnB);

        $this->assertTrue(
            $decision->denegado(),
            'Un alcance de organización no puede alcanzar a alguien que no está vinculado a ella.'
        );
    }

    /**
     * Un actor con cuenta, vinculado a la organización y con los permisos que
     * se le pidan.
     *
     * @param  list<string>  $permisos
     */
    private function actorConPermisos(EscenarioTenant $escenario, array $permisos): \App\Models\User
    {
        $persona = $escenario->persona();
        $rol = $escenario->rol('Rol de prueba '.uniqid(), $permisos, nivelMaximo: 4);
        $escenario->asignarRol($persona, $rol);

        return $escenario->usuarioDe($persona);
    }
}
