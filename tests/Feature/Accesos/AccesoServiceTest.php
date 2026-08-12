<?php

declare(strict_types=1);

namespace Tests\Feature\Accesos;

use App\Domain\Accesos\Datos\Dimension;
use App\Domain\Accesos\Modelos\Bitacora;
use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Servicios\AccesoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Apoyo\EscenarioTenant;
use Tests\Apoyo\RecursoSensible;
use Tests\TestCase;

/**
 * Las cuatro dimensiones del Doc 06 §1.
 *
 * Cada prueba ataca UNA dimensión dejando las otras tres en verde, para que un
 * fallo diga cuál se rompió. Una prueba que niega por dos razones a la vez
 * seguiría pasando con una de las dos comprobaciones quitada.
 */
class AccesoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function servicio(): AccesoService
    {
        return app(AccesoService::class);
    }

    // ── Dimensión 1: permiso ──────────────────────────────────────────────

    public function test_sin_el_permiso_se_niega(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        // Rol con OTRO permiso: el actor tiene rol y alcance, pero no éste.
        $rol = $escenario->rol('Docente', ['personas.ver'], nivelMaximo: 2);
        $escenario->asignarRol($actor, $rol);

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Permiso, $decision->dimension);
    }

    public function test_con_permiso_alcance_y_sensibilidad_se_autoriza(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Orientador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $escenario->asignarRol($actor, $rol);

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue($decision->permitido, $decision->motivo);
    }

    // ── Dimensión 2: alcance ──────────────────────────────────────────────

    public function test_el_alcance_por_unidad_incluye_a_los_descendientes(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        // Plantel → Departamento → Academia. El actor alcanza el plantel.
        $plantel = $escenario->unidad(nombre: 'Plantel Norte');
        $departamento = $escenario->unidad($plantel, 'Secundaria');
        $academia = $escenario->unidad($departamento, 'Academia de Ciencias');

        $grupo = $escenario->agrupacion($academia, '3° A');

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();
        $escenario->inscribir($sujeto, $grupo);

        $rol = $escenario->rol('Coordinador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $escenario->asignarRol(
            $actor,
            $rol,
            PersonaRolAlcance::TIPO_UNIDAD,
            $plantel->id
        );

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue(
            $decision->permitido,
            'Un alcance sobre el plantel debe llegar a un grupo colgado dos niveles más abajo. '
            .$decision->motivo
        );
    }

    public function test_el_alcance_por_unidad_no_alcanza_a_otra_rama(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $norte = $escenario->unidad(nombre: 'Plantel Norte');
        $sur = $escenario->unidad(nombre: 'Plantel Sur');

        $grupoSur = $escenario->agrupacion($sur, '1° B');

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();
        $escenario->inscribir($sujeto, $grupoSur);

        $rol = $escenario->rol('Coordinador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $escenario->asignarRol($actor, $rol, PersonaRolAlcance::TIPO_UNIDAD, $norte->id);

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Alcance, $decision->dimension);
    }

    public function test_una_membresia_vencida_saca_a_la_persona_del_alcance(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $grupo = $escenario->agrupacion(nombre: '3° A');

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        // Dado de baja el mes pasado: el docente ya no debe verlo.
        $escenario->inscribir($sujeto, $grupo, Carbon::now()->subMonth()->toDateString());

        $rol = $escenario->rol('Docente', ['resultados.ver_resumen'], nivelMaximo: 1);
        $escenario->asignarRol(
            $actor,
            $rol,
            PersonaRolAlcance::TIPO_AGRUPACION,
            $grupo->id
        );

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_resumen', $sujeto);

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Alcance, $decision->dimension);
    }

    public function test_un_rol_vencido_no_concede_acceso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Orientador', ['resultados.ver_detalle'], nivelMaximo: 2);

        // El alcance caducó al cerrar el ciclo.
        $escenario->asignarRol(
            $actor,
            $rol,
            PersonaRolAlcance::TIPO_ORGANIZACION,
            null,
            vigenciaFin: Carbon::now()->subDay()->toDateString()
        );

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue(
            $decision->denegado(),
            'Un alcance con vigencia_fin en el pasado no debe conceder nada.'
        );
        $this->assertSame(Dimension::Alcance, $decision->dimension);
    }

    public function test_un_alcance_que_aun_no_empieza_no_concede_acceso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Orientador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $escenario->asignarRol(
            $actor,
            $rol,
            PersonaRolAlcance::TIPO_ORGANIZACION,
            null,
            vigenciaInicio: Carbon::now()->addWeek()->toDateString()
        );

        $decision = $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Alcance, $decision->dimension);
    }

    // ── Dimensión 3: sensibilidad ─────────────────────────────────────────

    public function test_la_sensibilidad_insuficiente_niega_aunque_haya_permiso_y_alcance(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        // Reclutador: permiso y alcance completos, tope laboral (2).
        $rol = $escenario->rol('Reclutador', ['resultados.ver_detalle'], nivelMaximo: 2);
        $escenario->asignarRol($actor, $rol);

        $decision = $this->servicio()->autorizar(
            $actor,
            'resultados.ver_detalle',
            $sujeto,
            RecursoSensible::deNivel(4)
        );

        $this->assertTrue(
            $decision->denegado(),
            'Un rol de tope 2 no puede ver un recurso clínico aunque tenga el permiso.'
        );
        $this->assertSame(Dimension::Sensibilidad, $decision->dimension);
    }

    public function test_el_psicologo_si_alcanza_el_nivel_clinico(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Psicólogo', ['resultados.ver_detalle'], nivelMaximo: 4);
        $escenario->asignarRol($actor, $rol);

        $decision = $this->servicio()->autorizar(
            $actor,
            'resultados.ver_detalle',
            $sujeto,
            RecursoSensible::deNivel(4)
        );

        $this->assertTrue($decision->permitido, $decision->motivo);
    }

    public function test_el_tope_sale_del_rol_que_trae_el_permiso_no_del_mas_alto(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        /*
         * La misma persona es psicóloga (nivel 4, sin este permiso) y docente
         * (nivel 1, con el permiso). Ejercer el permiso del rol docente no debe
         * traerse el nivel 4 del otro rol.
         */
        $psicologa = $escenario->rol('Psicólogo', ['alertas.atender'], nivelMaximo: 4);
        $docente = $escenario->rol('Docente', ['resultados.ver_resumen'], nivelMaximo: 1);

        $escenario->asignarRol($actor, $psicologa);
        $escenario->asignarRol($actor, $docente);

        $decision = $this->servicio()->autorizar(
            $actor,
            'resultados.ver_resumen',
            $sujeto,
            RecursoSensible::deNivel(3)
        );

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Sensibilidad, $decision->dimension);
    }

    // ── Alcances implícitos ───────────────────────────────────────────────

    public function test_el_titular_llega_a_lo_suyo_sin_rol_ni_alcance(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();

        $decision = $this->servicio()->autorizar(
            $persona,
            'resultados.ver_detalle',
            $persona,
            RecursoSensible::deNivel(4)
        );

        $this->assertTrue(
            $decision->permitido,
            'La persona es titular de su propio dato: no necesita que nadie se lo conceda.'
        );
    }

    public function test_el_tutor_vigente_llega_a_lo_de_su_tutelado(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $tutora = $escenario->persona();
        $menor = $escenario->persona();
        $escenario->tutoria($tutora, $menor);

        $decision = $this->servicio()->autorizar($tutora, 'expediente.ver', $menor);

        $this->assertTrue($decision->permitido, $decision->motivo);
    }

    public function test_una_tutoria_sin_validar_no_da_acceso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $supuestoTutor = $escenario->persona();
        $menor = $escenario->persona();

        /*
         * El caso que importa: alguien se registra declarando ser la madre. El
         * parentesco declarado no acredita nada hasta que un profesional lo
         * valida, y darle acceso mientras tanto sería entregarle el expediente
         * psicológico de un menor a un desconocido.
         */
        $escenario->tutoria($supuestoTutor, $menor, estado: 'pendiente_validacion');

        $decision = $this->servicio()->autorizar($supuestoTutor, 'expediente.ver', $menor);

        $this->assertTrue($decision->denegado());
        $this->assertSame(Dimension::Permiso, $decision->dimension);
    }

    // ── Bitácora ──────────────────────────────────────────────────────────

    public function test_toda_decision_queda_en_bitacora_incluidas_las_negadas(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $this->servicio()->autorizar($actor, 'resultados.ver_detalle', $sujeto);

        $registro = Bitacora::query()->latest('id')->first();

        $this->assertNotNull($registro, 'Una decisión negada también se registra.');
        $this->assertSame('denegado', $registro->resultado);
        $this->assertSame('resultados.ver_detalle', $registro->accion);
        $this->assertSame($actor->id, $registro->actor_persona_id);
        $this->assertSame($sujeto->id, $registro->persona_afectada_id);
        $this->assertSame($escenario->organizacion->id, $registro->organizacion_id);
        $this->assertNotNull($registro->motivo);
    }

    public function test_el_acceso_concedido_sin_verificar_consentimiento_se_distingue_en_bitacora(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();

        $rol = $escenario->rol('Orientador', ['expediente.ver'], nivelMaximo: 2);
        $escenario->asignarRol($actor, $rol);

        $this->servicio()->autorizar($actor, 'expediente.ver', $sujeto);

        $registro = Bitacora::query()->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertSame('permitido', $registro->resultado);

        /*
         * Mientras la verificación de consentimiento sea provisional (Fase 1),
         * los accesos concedidos así llevan motivo propio. Es lo que permite
         * responder, al conectar la real, exactamente qué se consultó sin
         * comprobarla — que es lo que preguntaría una auditoría de la LFPDPPP.
         */
        $this->assertStringContainsString('consentimiento pendiente', (string) $registro->motivo);
    }

    public function test_la_bitacora_no_se_puede_editar_ni_borrar(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $this->servicio()->autorizar($actor, 'expediente.ver', $escenario->persona());

        $registro = Bitacora::query()->latest('id')->firstOrFail();

        $this->expectException(LogicException::class);

        $registro->update(['resultado' => 'permitido']);
    }
}
