<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluaciones;

use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Catalogo\Modelos\ReglaSalto;
use App\Domain\Evaluaciones\Excepciones\AplicacionInvalida;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Evaluaciones\Servicios\MotorAplicacion;
use App\Domain\Evaluaciones\Servicios\RegistroRespuestas;
use App\Jobs\CalificarAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Apoyo\EscenarioAplicacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Los cinco casos que el Doc 08 exige para la Fase 6.
 */
class MotorAplicacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function motor(): MotorAplicacion
    {
        return app(MotorAplicacion::class);
    }

    private function registro(): RegistroRespuestas
    {
        return app(RegistroRespuestas::class);
    }

    // ── Entrega parcelada ─────────────────────────────────────────────────

    public function test_iniciar_devuelve_la_estructura_sin_enunciados(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(5);

        $aplicacion = $escenario->iniciar();
        $estructura = $this->motor()->estructura($aplicacion);

        $this->assertSame(1, count($estructura['bloques']));
        $this->assertSame(6, $estructura['bloques'][0]['total_reactivos']);

        /*
         * Cuántos, no cuáles. Un endpoint de arranque que devolviera los
         * enunciados sería la forma más cómoda de descargarse una prueba con
         * copyright (Doc 06 §3).
         */
        $serializada = json_encode($estructura);
        $this->assertStringNotContainsString('Enunciado de prueba', (string) $serializada);
    }

    public function test_los_reactivos_se_entregan_por_tramos(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(9);

        $aplicacion = $escenario->iniciar();

        $primero = $this->motor()->reactivosDe($aplicacion, 'B1', desde: 0, cantidad: 4);
        $segundo = $this->motor()->reactivosDe($aplicacion, 'B1', desde: 4, cantidad: 4);

        $this->assertCount(4, $primero['reactivos']);
        $this->assertCount(4, $segundo['reactivos']);
        $this->assertSame(10, $primero['total_visibles']);

        $this->assertNotSame(
            $primero['reactivos'][0]['codigo'],
            $segundo['reactivos'][0]['codigo']
        );
    }

    public function test_pedir_mil_reactivos_no_los_entrega(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(80);

        $aplicacion = $escenario->iniciar();
        $tramo = $this->motor()->reactivosDe($aplicacion, 'B1', desde: 0, cantidad: 1000);

        // Tope de servidor: pedir todo de una vez no es un caso de uso, es una
        // descarga.
        $this->assertCount(50, $tramo['reactivos']);
    }

    // ── Caso 1: idempotencia de lotes ─────────────────────────────────────

    public function test_reenviar_el_mismo_lote_no_duplica_respuestas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(3);

        $aplicacion = $escenario->iniciar();

        $lote = [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 2, (string) Str::uuid()),
            EscenarioAplicacion::respuesta($reactivos[1]->codigo, 3, (string) Str::uuid()),
        ];

        $primera = $this->registro()->recibir($aplicacion, $lote);
        $segunda = $this->registro()->recibir($aplicacion, $lote);

        $this->assertSame(2, $primera['aceptadas']);
        $this->assertSame(0, $primera['duplicadas']);

        /*
         * El cliente genera el uuid ANTES de mandar, así que reintentar un lote
         * que se perdió en la red no duplica nada. Es lo que hace posible el
         * modo offline de la V3.
         */
        $this->assertSame(0, $segunda['aceptadas']);
        $this->assertSame(2, $segunda['duplicadas']);

        $this->assertSame(2, Respuesta::query()->where('aplicacion_id', $aplicacion->id)->count());
    }

    public function test_un_reactivo_de_otro_instrumento_se_rechaza_sin_tumbar_el_lote(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(2);

        $aplicacion = $escenario->iniciar();

        $resultado = $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 1),
            EscenarioAplicacion::respuesta('NO_EXISTE', 1),
        ]);

        // La buena entra; la mala se reporta. Tumbar el lote entero perdería
        // respuestas legítimas por un código mal escrito.
        $this->assertSame(1, $resultado['aceptadas']);
        $this->assertCount(1, $resultado['rechazadas']);
    }

    // ── Caso 2: expiración de bloque con respuestas tardías ───────────────

    public function test_una_respuesta_con_el_bloque_expirado_se_marca_tardia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        // Bloque de un minuto.
        $escenario = new EscenarioAplicacion($tenant, tiempoLimiteSeg: 60);
        $reactivos = $escenario->reactivos(2);

        $aplicacion = $escenario->iniciar();

        // Arranca el cronómetro.
        $this->motor()->reactivosDe($aplicacion, 'B1');

        $resultado = $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta(
                $reactivos[0]->codigo,
                1,
                respondidaEn: Carbon::now()->addMinutes(5)->toIso8601String()
            ),
        ]);

        $this->assertSame(1, $resultado['aceptadas']);
        $this->assertSame(1, $resultado['tardias']);

        /*
         * NO se rechaza: perder la respuesta sería peor. Se marca, y qué hacer
         * con ella lo decide el pipeline.
         */
        $this->assertTrue(
            Respuesta::query()->where('aplicacion_id', $aplicacion->id)->value('tardia')
        );
    }

    public function test_dentro_de_tiempo_no_se_marca_tardia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant, tiempoLimiteSeg: 600);
        $reactivos = $escenario->reactivos(1);

        $aplicacion = $escenario->iniciar();
        $this->motor()->reactivosDe($aplicacion, 'B1');

        $resultado = $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 1),
        ]);

        $this->assertSame(0, $resultado['tardias']);
    }

    public function test_el_cronometro_se_calcula_en_el_servidor(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant, tiempoLimiteSeg: 300);
        $escenario->reactivos(1);

        $aplicacion = $escenario->iniciar();
        $tramo = $this->motor()->reactivosDe($aplicacion, 'B1');

        $this->assertNotNull($tramo['cronometro']);
        $this->assertLessThanOrEqual(300, $tramo['cronometro']['restante_seg']);
        $this->assertFalse($tramo['cronometro']['expirado']);

        $estado = $this->motor()->bloqueDe($aplicacion, 'B1');

        // Cinco minutos después: se acabó, sin que el cliente diga nada.
        $this->assertSame(0, $estado->restanteSeg(Carbon::now()->addMinutes(6)));
        $this->assertTrue($estado->expirado(Carbon::now()->addMinutes(6)));
    }

    // ── Caso 3: reanudación exacta ────────────────────────────────────────

    public function test_iniciar_dos_veces_reanuda_en_vez_de_empezar_de_cero(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(3);

        $primera = $escenario->iniciar();
        $this->registro()->recibir($primera, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 2),
        ]);

        $segunda = $escenario->iniciar();

        /*
         * Quien recarga la pantalla a media prueba tiene que volver donde
         * estaba. Crear una aplicación nueva perdería lo contestado y falsearía
         * el conteo de intentos.
         */
        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, $segunda->respuestas()->count());
    }

    public function test_la_pausa_congela_el_cronometro_y_reanudar_lo_continua(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant, tiempoLimiteSeg: 600);
        $escenario->reactivos(2);

        $aplicacion = $escenario->iniciar();
        $this->motor()->reactivosDe($aplicacion, 'B1');

        $this->motor()->pausar($aplicacion);

        $estado = $this->motor()->bloqueDe($aplicacion->refresh(), 'B1');

        // Sin `iniciado_en`, el reloj no corre: una pausa de dos horas no
        // cuenta como tiempo de respuesta.
        $this->assertNull($estado->iniciado_en);
        $this->assertSame('en_pausa', $aplicacion->refresh()->estado);

        $restanteEnPausa = $estado->restanteSeg(Carbon::now()->addHours(2));

        $this->assertGreaterThan(
            500,
            $restanteEnPausa,
            'Dos horas en pausa no pueden consumir el cronómetro.'
        );

        $this->motor()->reanudar($aplicacion);

        $this->assertSame('iniciada', $aplicacion->refresh()->estado);
        $this->assertNotNull($this->motor()->bloqueDe($aplicacion, 'B1')->iniciado_en);
    }

    public function test_el_estado_devuelve_lo_necesario_para_reanudar(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(4);

        $aplicacion = $escenario->iniciar();
        $this->motor()->reactivosDe($aplicacion, 'B1');

        $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 1),
            EscenarioAplicacion::respuesta($reactivos[1]->codigo, 2),
        ]);

        $estado = $this->motor()->estado($aplicacion);

        $this->assertSame('iniciada', $estado['estado']);
        $this->assertSame(2, $estado['respondidos']);
        $this->assertSame('B1', $estado['bloque_actual']);
        $this->assertSame('en_curso', $estado['bloques']['B1']);
    }

    // ── Caso 4: salto condicionado ────────────────────────────────────────

    public function test_un_salto_oculta_los_reactivos_intermedios(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(4);

        // Si el primero vale más de 2, se salta al cuarto.
        ReglaSalto::query()->create([
            'version_instrumento_id' => $escenario->asignacion->instrumento->version->id,
            'reactivo_origen_id' => $reactivos[0]->id,
            'condicion' => 'mayor',
            'valor' => '2',
            'destino_tipo' => 'reactivo',
            'destino_id' => $reactivos[3]->id,
        ]);

        $aplicacion = $escenario->iniciar();

        $antes = $this->motor()->reactivosDe($aplicacion, 'B1', cantidad: 50);
        $this->assertSame(5, $antes['total_visibles']);

        $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 3),
        ]);

        $despues = $this->motor()->reactivosDe($aplicacion, 'B1', cantidad: 50);
        $codigos = array_column($despues['reactivos'], 'codigo');

        /*
         * Los dos de en medio desaparecen. Se resuelve en el SERVIDOR: mandarle
         * el árbol de saltos al cliente le entregaría el mapa del instrumento.
         */
        $this->assertNotContains($reactivos[1]->codigo, $codigos);
        $this->assertNotContains($reactivos[2]->codigo, $codigos);
        $this->assertContains($reactivos[3]->codigo, $codigos);
    }

    public function test_un_salto_que_no_se_cumple_no_oculta_nada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(4);

        ReglaSalto::query()->create([
            'version_instrumento_id' => $escenario->asignacion->instrumento->version->id,
            'reactivo_origen_id' => $reactivos[0]->id,
            'condicion' => 'mayor',
            'valor' => '2',
            'destino_tipo' => 'reactivo',
            'destino_id' => $reactivos[3]->id,
        ]);

        $aplicacion = $escenario->iniciar();

        // Responde 1: no dispara.
        $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 1),
        ]);

        $tramo = $this->motor()->reactivosDe($aplicacion, 'B1', cantidad: 50);

        $this->assertSame(5, $tramo['total_visibles']);
    }

    // ── Caso 5: centinela con la aplicación en curso ──────────────────────

    public function test_un_centinela_dispara_alerta_con_la_aplicacion_en_curso(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(2);
        $centinela = $escenario->centinela('Ideación referida: atender de inmediato.');

        $aplicacion = $escenario->iniciar();

        $resultado = $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($centinela->codigo, 2),
        ]);

        $this->assertSame(1, $resultado['alertas_generadas']);

        $alerta = Alerta::query()->latest('id')->first();

        $this->assertNotNull($alerta);
        $this->assertSame('centinela', $alerta->tipo);
        $this->assertSame('critica', $alerta->severidad);
        $this->assertSame($centinela->id, $alerta->reactivo_id);
        $this->assertSame($aplicacion->id, $alerta->aplicacion_id);

        /*
         * LO QUE IMPORTA: la aplicación sigue abierta. La alerta no espera a
         * que termine ni a que la cola califique — es la diferencia entre
         * enterarse ahora o mañana.
         */
        $this->assertSame('iniciada', $aplicacion->refresh()->estado);
    }

    public function test_un_valor_bajo_el_umbral_no_dispara(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $centinela = $escenario->centinela();

        $aplicacion = $escenario->iniciar();

        $resultado = $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($centinela->codigo, 0),
        ]);

        $this->assertSame(0, $resultado['alertas_generadas']);
        $this->assertSame(0, Alerta::query()->count());
    }

    public function test_una_aplicacion_anonima_genera_alerta_sin_persona(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $centinela = $escenario->centinela();

        $escenario->destinatario->asignacion->update(['es_anonima' => true]);

        $aplicacion = $escenario->iniciar();

        $this->assertNull($aplicacion->persona_id, 'Anónima: el vínculo no se guarda.');

        $this->registro()->recibir($aplicacion, [
            EscenarioAplicacion::respuesta($centinela->codigo, 3),
        ]);

        $alerta = Alerta::query()->latest('id')->firstOrFail();

        /*
         * Hay alerta —el riesgo existe y queda registrado— pero no hay a quién
         * atribuirla. Es el precio del anonimato y está asumido: el protocolo
         * del tenant se dirige al centro de trabajo, no a una persona.
         */
        $this->assertNull($alerta->persona_id);
        $this->assertSame('critica', $alerta->severidad);
    }

    // ── Finalizar ─────────────────────────────────────────────────────────

    public function test_finalizar_encola_el_pipeline_y_cierra_al_destinatario(): void
    {
        Queue::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(1);

        $aplicacion = $escenario->iniciar();
        $this->motor()->finalizar($aplicacion);

        $this->assertSame('completada', $aplicacion->refresh()->estado);
        $this->assertSame('completada', $escenario->destinatario->refresh()->estado);

        Queue::assertPushed(
            CalificarAplicacion::class,
            fn (CalificarAplicacion $job): bool => $job->aplicacionId === $aplicacion->id
        );
    }

    public function test_una_aplicacion_completada_no_admite_mas_respuestas(): void
    {
        Queue::fake();

        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $reactivos = $escenario->reactivos(1);

        $aplicacion = $escenario->iniciar();
        $this->motor()->finalizar($aplicacion);

        $this->expectException(AplicacionInvalida::class);

        $this->registro()->recibir($aplicacion->refresh(), [
            EscenarioAplicacion::respuesta($reactivos[0]->codigo, 1),
        ]);
    }

    public function test_no_se_inicia_con_la_ventana_cerrada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);
        $escenario->reactivos(1);

        /*
         * Se mueven LAS DOS fechas al pasado. Bajar sólo `ventana_fin` choca
         * contra el CHECK `ventana_fin > ventana_inicio` —que es justo lo que
         * tiene que hacer—: una ventana invertida no debe poder existir ni
         * siquiera para montar una prueba.
         */
        $escenario->destinatario->asignacion->update([
            'ventana_inicio' => Carbon::now()->subDays(10),
            'ventana_fin' => Carbon::now()->subDay(),
        ]);

        $this->expectException(AplicacionInvalida::class);

        $escenario->iniciar();
    }
}
