<?php

declare(strict_types=1);

namespace Tests\Feature\Consentimientos;

use App\Domain\Consentimientos\Modelos\SolicitudArco;
use App\Domain\Consentimientos\Servicios\GestorArco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Derechos ARCO (Doc 06 §3 — LFPDPPP).
 *
 * Lo que se prueba no es que haya un formulario: es que haya PLAZOS
 * registrados y respuesta documentada. Una solicitud que se traspapela es un
 * incumplimiento con sanción.
 */
class ArcoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_recibir_calcula_el_plazo_en_dias_habiles(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        // Un lunes, para que la cuenta sea verificable a mano.
        Carbon::setTestNow(Carbon::parse('2026-03-02 10:00:00'));

        $titular = $tenant->persona();
        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'acceso',
            'Quiero copia de mi expediente psicométrico completo.',
        );

        Carbon::setTestNow();

        /*
         * 20 días hábiles desde el lunes 2 de marzo: cuatro semanas exactas,
         * el lunes 30. El plazo se guarda al recibir y no se recalcula:
         * hacerlo después con el calendario de hoy daría otra fecha si cambian
         * los asuetos.
         */
        $this->assertSame('2026-03-30', $solicitud->vence_respuesta->toDateString());
        $this->assertSame('recibida', $solicitud->estado);
    }

    public function test_responder_una_improcedente_exige_documentar_la_excepcion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $titular = $tenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'cancelacion',
            'Quiero que borren todo lo que tienen de mí.',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/en qué excepción se funda/');

        /*
         * Hay datos que la organización está obligada a conservar —la bitácora,
         * por ejemplo— y eso se puede sostener. Lo que no se sostiene es no
         * explicarlo: una negativa sin fundamento es una queja ante el INAI.
         */
        app(GestorArco::class)->responder(
            $solicitud,
            $tenant->persona(),
            'improcedente',
            'No procede la cancelación solicitada.',
        );
    }

    public function test_una_procedente_abre_el_plazo_de_cumplimiento(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $titular = $tenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'acceso',
            'Solicito copia de mi expediente.',
        );

        $resuelta = app(GestorArco::class)->responder(
            $solicitud,
            $tenant->persona(),
            'procedente',
            'Procede. Se entrega el expediente exportado en formato electrónico.',
        );

        $this->assertSame('procedente', $resuelta->estado);
        $this->assertNotNull($resuelta->vence_cumplimiento);
        $this->assertNotNull($resuelta->respondida_en);
    }

    public function test_la_respuesta_no_puede_ir_vacia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $titular = $tenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'oposicion',
            'Me opongo al tratamiento con fines de selección laboral.',
        );

        $this->expectExceptionMessageMatches('/no puede ir vacía/');

        // La ley exige respuesta DOCUMENTADA, no silencio.
        app(GestorArco::class)->responder($solicitud, $tenant->persona(), 'procedente', '   ');
    }

    public function test_la_exportacion_entrega_el_expediente_y_deja_bitacora(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $titular = $tenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'acceso',
            'Solicito copia de mi expediente.',
        );

        $exportado = app(GestorArco::class)->exportarExpediente($solicitud);

        $this->assertSame($titular->nombreCompleto(), $exportado['persona']['nombre']);
        $this->assertArrayHasKey('expediente', $exportado);
        $this->assertArrayHasKey('resultados', $exportado);

        $this->assertDatabaseHas('bitacora', [
            'accion' => 'arco.exportacion',
            'persona_afectada_id' => $titular->id,
        ]);
    }

    public function test_una_solicitud_vencida_se_reconoce_como_tal(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $titular = $tenant->persona();

        $solicitud = app(GestorArco::class)->recibir(
            $titular,
            $titular,
            'rectificacion',
            'Mi segundo apellido está mal escrito en el expediente.',
        );

        // Un mes después, sin responder.
        $despues = Carbon::now()->addMonths(2);

        /*
         * Se compara contra HOY y no contra `respondida_en`: una solicitud
         * contestada tarde ya venció aunque tenga respuesta, y el registro
         * tiene que poder decirlo.
         */
        $this->assertTrue($solicitud->vencida($despues));
        $this->assertFalse($solicitud->vencida());
    }

    public function test_la_api_recibe_y_responde_solicitudes(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $encargada = $tenant->persona();
        $tenant->asignarRol($encargada, $tenant->rol('Encargada de datos', [
            'arco.gestionar', 'personas.ver',
        ], 4));

        $titular = $tenant->persona();

        $sesion = $this->actingAs($tenant->usuarioDe($encargada))
            ->withSession(['organizacion_id' => $tenant->organizacion->id]);

        $creada = $sesion->postJson('/api/v1/arco', [
            'persona_uuid' => $titular->uuid,
            'derecho' => 'acceso',
            'descripcion' => 'Solicito copia íntegra de mi expediente psicométrico.',
        ], ['X-Organizacion' => (string) $tenant->organizacion->id]);

        $creada->assertStatus(201)->assertJsonStructure(['uuid', 'vence_respuesta']);

        $solicitud = SolicitudArco::query()->where('uuid', $creada->json('uuid'))->firstOrFail();

        $sesion->getJson(
            '/api/v1/arco/'.$solicitud->uuid.'/exportar',
            ['X-Organizacion' => (string) $tenant->organizacion->id],
        )->assertOk()->assertJsonStructure(['persona', 'expediente', 'resultados']);
    }

    public function test_sin_permiso_no_se_atienden_solicitudes(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $auxiliar = $tenant->persona();
        $tenant->asignarRol($auxiliar, $tenant->rol('Auxiliar', ['personas.ver'], 1));

        $this->actingAs($tenant->usuarioDe($auxiliar))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson('/api/v1/arco', ['X-Organizacion' => (string) $tenant->organizacion->id])
            ->assertForbidden();
    }
}
