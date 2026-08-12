<?php

declare(strict_types=1);

namespace Tests\Feature\Accesos;

use App\Domain\Accesos\Excepciones\RolNoModificable;
use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Servicios\GestorRoles;
use App\Domain\Organizaciones\Modelos\TipoOrganizacion;
use App\Domain\Organizaciones\Servicios\CreadorOrganizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

class GestionRolesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function gestor(): GestorRoles
    {
        return app(GestorRoles::class);
    }

    public function test_al_crear_un_tenant_hereda_las_plantillas_de_su_tipo(): void
    {
        /*
         * Por CreadorOrganizacion, no por la factory: la clonación de roles es
         * parte del alta y va en su misma transacción. Una factory que la
         * ejecutara metería lógica de negocio en las pruebas y escondería
         * justo lo que aquí se comprueba.
         */
        $escuela = TipoOrganizacion::query()->where('clave', 'escuela')->firstOrFail();

        $organizacion = app(CreadorOrganizacion::class)->crear('Escuela de prueba', $escuela->id);

        (new EscenarioTenant($organizacion))->activar();

        $roles = $this->gestor()->listar();

        $this->assertGreaterThan(
            0,
            $roles->count(),
            'Una organización sin roles no tiene forma de que nadie entre a arreglarla: '
            .'asignar roles exige un rol.'
        );

        $psicologo = $roles->firstWhere('name', 'Psicólogo');

        $this->assertNotNull($psicologo);
        $this->assertSame(
            4,
            $psicologo->nivelSensibilidadMaximo(),
            'El psicólogo es el único rol que alcanza lo clínico.'
        );
        $this->assertTrue($psicologo->hasPermissionTo('alertas.atender', 'web'));

        $orientador = $roles->firstWhere('name', 'Orientador');

        $this->assertNotNull(
            $orientador,
            'El nombre del rol depende del tipo de tenant: orientador en escuela, '
            .'reclutador en empresa.'
        );
        $this->assertSame(2, $orientador->nivelSensibilidadMaximo());
    }

    public function test_cada_tenant_recibe_su_propia_copia_de_los_roles(): void
    {
        $escuela = TipoOrganizacion::query()->where('clave', 'escuela')->firstOrFail();
        $creador = app(CreadorOrganizacion::class);

        $primera = $creador->crear('Escuela A', $escuela->id);
        $segunda = $creador->crear('Escuela B', $escuela->id);

        $rolesA = Rol::query()->where('organizacion_id', $primera->id)->pluck('id');
        $rolesB = Rol::query()->where('organizacion_id', $segunda->id)->pluck('id');

        $this->assertGreaterThan(0, $rolesA->count());

        /*
         * Copias, no referencias a la plantilla. Si apuntaran a la global,
         * corregir una plantilla cambiaría los permisos efectivos de todos los
         * tenants en producción sin que ninguno lo pidiera.
         */
        $this->assertEmpty(
            $rolesA->intersect($rolesB)->all(),
            'Dos organizaciones no pueden compartir la misma fila de rol.'
        );
    }

    public function test_se_crea_un_rol_con_sus_permisos_y_su_tope(): void
    {
        EscenarioTenant::nuevo()->activar();

        $rol = $this->gestor()->crear('Auxiliar', ['personas.ver', 'expediente.ver'], 2);

        $this->assertTrue($rol->hasPermissionTo('personas.ver', 'web'));
        $this->assertFalse($rol->hasPermissionTo('resultados.ver_detalle', 'web'));
        $this->assertSame(2, $rol->nivelSensibilidadMaximo());
    }

    public function test_editar_un_rol_reemplaza_su_lista_de_permisos(): void
    {
        EscenarioTenant::nuevo()->activar();

        $rol = $this->gestor()->crear('Auxiliar', ['personas.ver', 'expediente.ver'], 2);

        $this->gestor()->actualizar($rol, 'Auxiliar de expediente', ['expediente.editar'], 1);

        $rol->refresh()->load('permissions');

        $this->assertSame('Auxiliar de expediente', $rol->name);
        $this->assertSame(['expediente.editar'], $rol->permissions->pluck('name')->all());
        $this->assertSame(
            1,
            $rol->nivelSensibilidadMaximo(),
            'Bajar el tope tiene que surtir efecto: es lo que cierra el acceso a lo clínico.'
        );
    }

    public function test_un_permiso_fuera_del_catalogo_se_rechaza(): void
    {
        EscenarioTenant::nuevo()->activar();

        $this->expectException(RolNoModificable::class);

        // Conceder algo que ningún código consulta no protege nada y sólo
        // confunde a quien configura el rol.
        $this->gestor()->crear('Inventado', ['permiso.que.no.existe'], 1);
    }

    public function test_no_puedes_quitarle_la_gestion_de_roles_a_tu_propio_rol(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $administrador = $escenario->persona();
        $rol = $escenario->rol('Administrador', ['roles.gestionar', 'personas.ver'], 2);
        $escenario->asignarRol($administrador, $rol);

        $this->expectException(RolNoModificable::class);

        /*
         * El auto-encierro: si se permitiera, quien edita se queda sin forma de
         * volver a esta pantalla, y si era el único rol con el permiso, la
         * organización entera pierde la administración de roles y sólo se
         * repara desde la consola.
         */
        $this->gestor()->actualizar($rol, 'Administrador', ['personas.ver'], 2, $administrador);
    }

    public function test_si_no_tienes_ese_rol_si_puedes_quitarle_la_gestion(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $administrador = $escenario->persona();
        $suyo = $escenario->rol('Administrador', ['roles.gestionar'], 2);
        $escenario->asignarRol($administrador, $suyo);

        $otro = $escenario->rol('Otro admin', ['roles.gestionar', 'personas.ver'], 2);

        $this->gestor()->actualizar($otro, 'Otro admin', ['personas.ver'], 1, $administrador);

        $this->assertFalse($otro->refresh()->hasPermissionTo('roles.gestionar', 'web'));
    }

    public function test_no_se_elimina_un_rol_con_alcances_vivos(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $rol = $escenario->rol('Orientador', ['personas.ver'], 2);
        $escenario->asignarRol($persona, $rol);

        try {
            $this->gestor()->eliminar($rol);
            $this->fail('Debía impedirse: el rol tiene alcances asignados.');
        } catch (RolNoModificable) {
            // Esperado.
        }

        /*
         * Lo que se protege: `persona_rol_alcances.rol_id` tiene FK en cascada,
         * así que borrar el rol se habría llevado en silencio el registro de
         * quién tenía acceso a qué.
         */
        $this->assertDatabaseHas('persona_rol_alcances', ['rol_id' => $rol->id]);
        $this->assertNotNull(Rol::query()->find($rol->id));
    }

    public function test_un_rol_sin_alcances_si_se_elimina(): void
    {
        EscenarioTenant::nuevo()->activar();

        $rol = $this->gestor()->crear('Temporal', ['personas.ver'], 1);
        $id = $rol->id;

        $this->gestor()->eliminar($rol);

        $this->assertNull(Rol::query()->find($id));
        $this->assertDatabaseMissing('rol_sensibilidad_max', ['rol_id' => $id]);
    }

    public function test_el_listado_solo_trae_los_roles_de_la_organizacion_activa(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $b->rol('Rol exclusivo de B', ['personas.ver'], 1);

        $a->activar();

        $this->assertNotContains(
            'Rol exclusivo de B',
            $this->gestor()->listar()->pluck('name')->all()
        );
    }

    public function test_no_se_puede_editar_un_rol_de_otro_tenant(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $rolDeB = $b->rol('Psicólogo de B', ['resultados.ver_detalle'], 4);

        $a->activar();
        $actor = $a->persona();
        $a->asignarRol($actor, $a->rol('Admin de A', ['roles.gestionar'], 2));
        $usuario = $a->usuarioDe($actor);

        $respuesta = $this->actingAs($usuario)
            ->withSession(['organizacion_id' => $a->organizacion->id])
            ->put('/roles/'.$rolDeB->id, [
                'nombre' => 'Secuestrado',
                'permisos' => ['personas.ver'],
                'nivel_sensibilidad_max' => 1,
            ]);

        $respuesta->assertNotFound();
        $this->assertSame('Psicólogo de B', $rolDeB->refresh()->name);
    }

    public function test_la_pantalla_de_roles_exige_el_permiso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $escenario->asignarRol($persona, $escenario->rol('Docente', ['personas.ver'], 1));

        $respuesta = $this->actingAs($escenario->usuarioDe($persona))
            ->withSession(['organizacion_id' => $escenario->organizacion->id])
            ->get('/roles');

        $respuesta->assertForbidden();
    }

    public function test_la_api_entrega_el_catalogo_de_permisos(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $escenario->asignarRol($persona, $escenario->rol('Admin', ['roles.gestionar'], 2));

        $respuesta = $this->actingAs($escenario->usuarioDe($persona), 'sanctum')
            ->withHeader('X-Organizacion', (string) $escenario->organizacion->id)
            ->getJson('/api/v1/roles/catalogo-permisos');

        $respuesta->assertOk();
        $respuesta->assertJsonStructure([
            'data' => [['clave', 'dominio', 'etiqueta', 'descripcion']],
        ]);
    }

    public function test_bajar_el_tope_de_un_rol_le_cierra_el_acceso_clinico(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Psicólogo', ['resultados.ver_detalle'], 4);
        $escenario->asignarRol($actor, $rol);

        $accesos = app(\App\Domain\Accesos\Servicios\AccesoService::class);
        $recurso = \Tests\Apoyo\RecursoSensible::deNivel(4);

        $this->assertTrue(
            $accesos->autorizar($actor, 'resultados.ver_detalle', $sujeto, $recurso)->permitido
        );

        // El efecto que importa: editar el rol cambia lo que su gente ve.
        $this->gestor()->actualizar($rol, 'Psicólogo', ['resultados.ver_detalle'], 2);

        $this->assertTrue(
            $accesos->autorizar($actor, 'resultados.ver_detalle', $sujeto, $recurso)->denegado(),
            'Bajar el tope a 2 tiene que cerrar el acceso a un recurso clínico.'
        );
    }

    public function test_los_alcances_sobreviven_a_la_edicion_del_rol(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $rol = $escenario->rol('Orientador', ['personas.ver'], 2);
        $escenario->asignarRol($persona, $rol);

        $this->gestor()->actualizar($rol, 'Orientador escolar', ['personas.ver', 'expediente.ver'], 2);

        $this->assertSame(
            1,
            PersonaRolAlcance::query()->where('rol_id', $rol->id)->count(),
            'Editar los permisos de un rol no puede tocar a quién está asignado.'
        );
    }
}
