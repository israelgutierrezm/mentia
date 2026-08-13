<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluaciones;

use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Servicios\NotificadorAsignaciones;
use App\Domain\Evaluaciones\Servicios\RecordatorioProgramado;
use App\Mail\InvitacionAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

class RecordatoriosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function programado(): RecordatorioProgramado
    {
        return app(RecordatorioProgramado::class);
    }

    /**
     * Una asignación notificada, con la persona todavía sin contestar.
     *
     * @return array{0: \App\Domain\Evaluaciones\Modelos\Asignacion, 1: AsignacionDestinatario}
     */
    private function notificada(EscenarioTenant $tenant, ?string $ventanaFin = null): array
    {
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $tenant->usuarioDe($persona);

        $asignacion = $escenario->individual(
            $tenant->persona(),
            [$persona],
            ventanaFin: $ventanaFin ?? Carbon::now()->addDays(10)->toDateTimeString()
        );

        app(NotificadorAsignaciones::class)->invitar($asignacion);

        return [$asignacion, $asignacion->destinatarios()->first()];
    }

    public function test_no_recuerda_el_mismo_dia_de_la_invitacion(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion] = $this->notificada($tenant);

        Mail::assertSentCount(1);

        $enviados = $this->programado()->paraAsignacion($asignacion);

        /*
         * Un sistema que insiste el mismo día se gana que lo marquen como
         * spam, y entonces tampoco llega la invitación de la siguiente
         * campaña.
         */
        $this->assertSame(0, $enviados);
        Mail::assertSentCount(1);
    }

    public function test_recuerda_pasada_la_cadencia_minima(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada($tenant);

        $despues = Carbon::now()->addDays(RecordatorioProgramado::DIAS_ENTRE_RECORDATORIOS);

        $this->assertSame(1, $this->programado()->paraAsignacion($asignacion, $despues));

        Mail::assertSent(InvitacionAplicacion::class, 2);
        $this->assertSame(1, $destinatario->refresh()->recordatorios_enviados);
    }

    public function test_deja_de_insistir_al_llegar_al_tope(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada($tenant);

        $destinatario->update([
            'recordatorios_enviados' => RecordatorioProgramado::TOPE,
            'notificada_en' => Carbon::now()->subDays(30),
        ]);

        /*
         * Quien no contestó tras tres avisos no va a contestar por un cuarto;
         * lo que hace falta ahí es que alguien lo llame.
         */
        $this->assertSame(
            0,
            $this->programado()->paraAsignacion($asignacion, Carbon::now()->addDay())
        );
    }

    public function test_el_ultimo_dia_se_salta_la_cadencia(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada(
            $tenant,
            Carbon::now()->addDays(3)->toDateTimeString()
        );

        // Se le escribió ayer: normalmente no tocaría.
        $destinatario->update(['notificada_en' => Carbon::now()->addDays(2)]);

        /*
         * Cinco minutos antes de que cierre, no una hora fija del día: con
         * `startOfDay()->addHours(10)` la prueba fallaba si la suite corría
         * antes de las diez de la mañana, porque ese instante quedaba DESPUÉS
         * de `ventana_fin` y la ventana ya estaba cerrada. Una prueba que
         * depende de la hora a la que se ejecute no prueba lo que dice.
         */
        $ultimoDia = $asignacion->ventana_fin->copy()->subMinutes(5);

        /*
         * Es la única insistencia que sirve de verdad: después ya no hay nada
         * que hacer.
         */
        $this->assertSame(1, $this->programado()->paraAsignacion($asignacion, $ultimoDia));
    }

    public function test_no_recuerda_con_la_ventana_cerrada(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion] = $this->notificada(
            $tenant,
            Carbon::now()->addDay()->toDateTimeString()
        );

        $despues = Carbon::now()->addDays(5);

        // Recordar algo que ya no se puede contestar sólo genera llamadas a
        // soporte.
        $this->assertSame(0, $this->programado()->paraAsignacion($asignacion, $despues));
    }

    public function test_no_recuerda_a_quien_ya_contesto(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada($tenant);

        $destinatario->update(['estado' => 'completada']);

        $this->assertSame(
            0,
            $this->programado()->paraAsignacion(
                $asignacion,
                Carbon::now()->addDays(RecordatorioProgramado::DIAS_ENTRE_RECORDATORIOS)
            )
        );
    }

    public function test_no_recuerda_a_quien_quedo_exento(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada($tenant);

        $destinatario->update(['estado' => 'exenta', 'motivo_exencion' => 'Incapacidad.']);

        $this->assertSame(
            0,
            $this->programado()->paraAsignacion(
                $asignacion,
                Carbon::now()->addDays(RecordatorioProgramado::DIAS_ENTRE_RECORDATORIOS)
            )
        );
    }

    public function test_el_barrido_general_ve_todas_las_organizaciones(): void
    {
        Mail::fake();

        $a = EscenarioTenant::nuevo()->activar();
        $this->notificada($a);

        $b = EscenarioTenant::nuevo()->activar();
        $this->notificada($b);

        // Sin contexto de tenant, como corre el job.
        app(\App\Soporte\Multitenencia\ContextoOrganizacion::class)->limpiar();

        $enviados = $this->programado()->correr(
            Carbon::now()->addDays(RecordatorioProgramado::DIAS_ENTRE_RECORDATORIOS)
        );

        /*
         * Los global scopes fallan cerrado: sin `sinRestriccion()` el job no
         * vería ninguna asignación y no mandaría nada, en silencio.
         */
        $this->assertSame(2, $enviados);
    }

    public function test_el_recordatorio_regenera_el_token(): void
    {
        Mail::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        [$asignacion, $destinatario] = $this->notificada($tenant);

        $anterior = $destinatario->refresh()->token;

        $this->programado()->paraAsignacion(
            $asignacion,
            Carbon::now()->addDays(RecordatorioProgramado::DIAS_ENTRE_RECORDATORIOS)
        );

        /*
         * Consecuencia de que el claro sólo exista al generarse. Es correcto
         * —una liga vieja circulando es una liga que alguien más puede usar—
         * y el texto del correo lo advierte.
         */
        $this->assertNotSame($anterior, $destinatario->refresh()->token);
    }
}
