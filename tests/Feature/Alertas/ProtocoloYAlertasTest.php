<?php

declare(strict_types=1);

namespace Tests\Feature\Alertas;

use App\Domain\Alertas\Excepciones\AlertaSinResolucion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Alertas\Modelos\AlertaDestinatario;
use App\Domain\Alertas\Modelos\ProtocoloEjecucion;
use App\Domain\Alertas\Modelos\ProtocoloRegla;
use App\Domain\Alertas\Servicios\AlertaService;
use App\Domain\Alertas\Servicios\ProtocoloDeActuacion;
use App\Domain\Evaluaciones\Excepciones\AsignacionInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Mail\AlertaCritica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioCentinela;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Fase 8 — el protocolo de actuación, la notificación real y el escalonamiento.
 *
 * Lo que se prueba aquí no es que las tablas guarden filas: es que una alerta
 * de riesgo llegue a alguien, que no se pueda cerrar sin decir qué se hizo, y
 * que un instrumento que detecta ideación suicida no se pueda asignar sin
 * haber definido quién responde.
 */
class ProtocoloYAlertasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PROTOCOLO = 'La psicóloga de guardia atiende las alertas críticas en menos de '
        .'dos horas hábiles, contacta a la familia el mismo día y canaliza al centro de salud '
        .'mental municipal cuando el riesgo se confirma. Escalamiento a dirección a las 24 horas.';

    // ── La compuerta del protocolo de actuación ───────────────────────────

    public function test_sin_protocolo_no_se_asigna_un_instrumento_con_centinelas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        // El instrumento detecta riesgo.
        $escenario->instrumento->reactivo('likert_5', 'E1', esCentinela: true);

        $this->expectException(AsignacionInvalida::class);
        $this->expectExceptionMessageMatches('/protocolo de actuación/');

        /*
         * Encender el detector sin decir quién responde produce una alerta
         * crítica a las once de la noche en un buzón que nadie mira hasta el
         * lunes. Es la única comprobación del sistema que existe para proteger
         * a quien contesta, no a los datos.
         */
        $escenario->individual($tenant->persona(), [$tenant->persona()]);
    }

    public function test_con_protocolo_y_destinatarios_si_se_asigna(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $escenario->instrumento->reactivo('likert_5', 'E1', esCentinela: true);

        $this->registrarProtocolo($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);

        $this->assertInstanceOf(Asignacion::class, $asignacion);
    }

    public function test_un_instrumento_sin_centinelas_no_exige_protocolo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        // Sin protocolo registrado y sin centinelas: se asigna sin problema.
        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);

        $this->assertInstanceOf(Asignacion::class, $asignacion);
    }

    public function test_un_protocolo_de_tres_palabras_no_cuenta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $protocolo = app(ProtocoloDeActuacion::class);

        $protocolo->registrar($tenant->organizacion, 'Ya lo vemos.', $tenant->persona()->id);

        // El mínimo no garantiza calidad, pero impide despachar el requisito
        // con un punto.
        $this->assertFalse($protocolo->registrado($tenant->organizacion->refresh()));
    }

    // ── La notificación real ──────────────────────────────────────────────

    public function test_una_alerta_critica_llega_por_correo_a_quien_tiene_el_rol(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        $psicologa = $tenant->persona();
        $rol = $tenant->rol('Psicóloga de guardia', ['resultados.ver_detalle'], 4);
        $tenant->asignarRol($psicologa, $rol);
        $cuenta = $tenant->usuarioDe($psicologa);

        AlertaDestinatario::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'tipo' => 'centinela',
            'severidad' => 'critica',
            'rol_id' => $rol->id,
            'canal' => 'correo',
        ]);

        $escenario->dispararCentinela();

        Mail::assertSent(AlertaCritica::class, function (AlertaCritica $correo) use ($cuenta): bool {
            return $correo->hasTo($cuenta->email);
        });
    }

    public function test_el_correo_de_alerta_no_lleva_el_contenido_de_la_respuesta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        $alerta = $escenario->dispararCentinela();
        $persona = $tenant->persona();

        $render = (new AlertaCritica($alerta, $persona))->render();

        /*
         * Un correo con "contestó que sí a la pregunta de ideación suicida"
         * viaja por servidores que no son de nadie y se queda en la bandeja de
         * entrada de quien lo reciba, para siempre.
         */
        $this->assertStringNotContainsString($alerta->mensaje, $render);
        $this->assertStringContainsString('centro de alertas', $render);
    }

    public function test_sin_destinatarios_la_alerta_se_registra_igual(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        $alerta = $escenario->dispararCentinela();

        /*
         * Notificar y registrar son actos separados: si un problema de canal
         * pudiera impedir el registro, desaparecería el rastro de que hubo un
         * riesgo detectado.
         */
        $this->assertDatabaseHas('alertas', ['id' => $alerta->id, 'severidad' => 'critica']);
    }

    // ── El cierre con resolución obligatoria ──────────────────────────────

    public function test_una_alerta_no_se_cierra_sin_decir_que_se_hizo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        $alerta = $escenario->dispararCentinela();

        $this->expectException(AlertaSinResolucion::class);

        /*
         * Una alerta que se puede cerrar con un clic se cierra con un clic, y
         * entonces el registro no dice si alguien habló con la persona o si
         * sólo quitaron el punto rojo de la pantalla.
         */
        app(AlertaService::class)->atender($alerta, $tenant->persona(), 'ok');
    }

    public function test_cerrarla_con_resolucion_deja_quien_y_cuando(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCentinela($tenant);

        $alerta = $escenario->dispararCentinela();
        $psicologa = $tenant->persona();

        $cerrada = app(AlertaService::class)->atender(
            $alerta,
            $psicologa,
            'Se contactó a la madre el mismo día y se canalizó al centro de salud mental.',
        );

        $this->assertSame('cerrada', $cerrada->estado);
        $this->assertSame($psicologa->id, $cerrada->atendida_por);
        $this->assertNotNull($cerrada->atendida_en);
    }

    // ── El escalonamiento automático ──────────────────────────────────────

    public function test_el_mchat_de_riesgo_medio_asigna_la_segunda_etapa(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        foreach (range(1, 6) as $ignorado) {
            $escenario->reactivoDeSuma('TOTAL', [0, 1]);
        }

        $escenario->instrumento->escala('SEG');

        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->pipeline('algoritmos', 'mchat_dos_etapas', [
            'escala' => 'TOTAL',
            'escala_seguimiento' => 'SEG',
        ]);

        // La regla: riesgo medio pendiente de entrevista → asignar la etapa 2.
        $segundaEtapa = new EscenarioAsignacion($tenant);

        ProtocoloRegla::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'si_version_instrumento_id' => $escenario->instrumento->version->id,
            'condicion_escala_id' => $escenario->instrumento->escalas['TOTAL']->id,
            'tipo_puntaje' => 'semaforo',
            'operador' => 'contiene',
            'valor' => 'riesgo_medio',
            'entonces_accion' => 'asignar_instrumento',
            'entonces_ref_id' => $segundaEtapa->instrumento->version->id,
            'nota' => 'M-CHAT: entrevista de seguimiento',
        ]);

        $asignacionesAntes = Asignacion::query()->count();

        $escenario->contestar([1, 1, 1, 1, 0, 0]);
        $escenario->calificar();

        /*
         * La mitad de los que caen en riesgo medio bajan a riesgo bajo tras la
         * entrevista. Que el sistema la dispare solo es lo que evita que se
         * quede sin aplicar.
         */
        $this->assertSame($asignacionesAntes + 1, Asignacion::query()->count());

        // Y NO pasa en silencio: hay alerta y hay bitácora.
        $this->assertDatabaseHas('alertas', [
            'aplicacion_id' => $escenario->aplicacion->id,
            'tipo' => 'protocolo',
        ]);

        $this->assertDatabaseHas('bitacora', [
            'accion' => 'protocolo.ejecutado',
            'recurso_id' => $escenario->aplicacion->id,
        ]);
    }

    public function test_recalificar_no_vuelve_a_disparar_el_protocolo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');

        ProtocoloRegla::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'si_version_instrumento_id' => $escenario->instrumento->version->id,
            'condicion_escala_id' => $escenario->instrumento->escalas['DEP']->id,
            'tipo_puntaje' => 'bruto',
            'operador' => '>=',
            'valor' => '2',
            'entonces_accion' => 'marcar_seguimiento',
            'nota' => 'Seguimiento por puntaje elevado',
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();
        $escenario->calificar();
        $escenario->calificar();

        /*
         * Sin esto, la familia recibiría tres veces la misma liga y el
         * psicólogo tres veces la misma alarma. A la tercera deja de mirarlas.
         */
        $this->assertSame(1, ProtocoloEjecucion::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->count());

        $this->assertSame(1, Alerta::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->where('tipo', 'protocolo')
            ->count());
    }

    public function test_una_regla_que_no_se_cumple_no_dispara_nada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');

        ProtocoloRegla::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'si_version_instrumento_id' => $escenario->instrumento->version->id,
            'condicion_escala_id' => $escenario->instrumento->escalas['DEP']->id,
            'tipo_puntaje' => 'bruto',
            'operador' => '>=',
            'valor' => '20',
            'entonces_accion' => 'marcar_seguimiento',
        ]);

        $escenario->contestar([1]);
        $escenario->calificar();

        $this->assertSame(0, ProtocoloEjecucion::query()->count());
    }

    private function registrarProtocolo(EscenarioTenant $tenant): void
    {
        app(ProtocoloDeActuacion::class)->registrar(
            $tenant->organizacion,
            self::PROTOCOLO,
            $tenant->persona()->id,
        );

        AlertaDestinatario::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'tipo' => 'centinela',
            'severidad' => 'critica',
            'rol_id' => $tenant->rol('Guardia')->id,
            'canal' => 'app',
        ]);
    }
}
