<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Expedientes\Modelos\ExpedienteValor;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Cifrado en reposo (Doc 06 §4).
 *
 * TODAS estas pruebas comparan contra la COLUMNA CRUDA, con `DB::table()`. Leer
 * por el modelo probaría que el cast descifra, que es justo lo que no está en
 * duda: lo que hay que demostrar es que quien abra un respaldo de la base no
 * pueda leer nada.
 *
 * La tabla del Doc 06 §4 lista cuatro cosas y aquí están las cuatro.
 */
class CifradoEnReposoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_una_respuesta_abierta_no_se_lee_en_la_base(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());
        $reactivo = $escenario->reactivoDeSuma('DEP');
        $escenario->contestar([]);

        $confesion = 'A veces pienso que estarían mejor sin mí.';

        Respuesta::query()->create([
            'aplicacion_id' => $escenario->aplicacion->id,
            'reactivo_id' => $reactivo->id,
            'valor_texto' => $confesion,
            'uuid_cliente' => (string) \Illuminate\Support\Str::uuid(),
            'respondida_en' => now(),
        ]);

        $crudo = (string) DB::table('respuestas')->value('valor_texto');

        $this->assertNotSame($confesion, $crudo);
        $this->assertStringNotContainsString('estarían mejor sin mí', $crudo);
    }

    public function test_un_valor_de_expediente_no_se_lee_en_la_base(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $persona = $tenant->persona();

        $campo = \App\Domain\Expedientes\Modelos\ExpedienteCampo::query()
            ->whereHas('seccion')
            ->firstOrFail();

        $expediente = \App\Domain\Expedientes\Modelos\Expediente::query()->create([
            'persona_id' => $persona->id,
        ]);

        $dato = 'Diagnóstico previo de trastorno de ansiedad generalizada.';

        ExpedienteValor::query()->create([
            'expediente_id' => $expediente->id,
            'campo_id' => $campo->id,
            'organizacion_id_contexto' => $tenant->organizacion->id,
            'valor_texto' => $dato,
            'capturado_por' => $persona->id,
            'estado' => 'validado',
            'version' => 1,
        ]);

        $crudo = (string) DB::table('expediente_valores')->value('valor_texto');

        $this->assertNotSame($dato, $crudo);
        $this->assertStringNotContainsString('ansiedad generalizada', $crudo);

        // Y por el modelo sí se lee: cifrar no puede costar la funcionalidad.
        $this->assertSame($dato, ExpedienteValor::query()->first()?->valor_texto);
    }

    public function test_una_interpretacion_resuelta_no_se_lee_en_la_base(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->regla('DEP', 'Puntaje compatible con sintomatología depresiva moderada.', [
            'operador' => '>=',
            'valor_min' => 0,
        ]);

        $escenario->contestar([2]);
        $escenario->calificar();

        $crudo = (string) DB::table('resultados_interpretacion')->value('texto_resuelto');

        /*
         * El puntaje no dice nada sin su baremo; este texto se entiende
         * leyéndolo. Es la diferencia entre un número y un expediente.
         */
        $this->assertStringNotContainsString('sintomatología depresiva', $crudo);

        $this->assertStringContainsString(
            'sintomatología depresiva',
            (string) ResultadoInterpretacion::query()->value('texto_resuelto'),
        );
    }

    public function test_una_nota_profesional_no_se_lee_en_la_base(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $persona = $tenant->persona();
        $autor = $tenant->persona();

        $expediente = \App\Domain\Expedientes\Modelos\Expediente::query()->create([
            'persona_id' => $persona->id,
        ]);

        $nota = 'La madre refiere violencia intrafamiliar en el domicilio.';

        \App\Domain\Expedientes\Modelos\NotaProfesional::query()->create([
            'expediente_id' => $expediente->id,
            'organizacion_id' => $tenant->organizacion->id,
            'autor_persona_id' => $autor->id,
            'contenido' => $nota,
            'nivel_sensibilidad_id' => \App\Domain\Accesos\Modelos\NivelSensibilidad::query()
                ->where('nivel', 4)->value('id'),
        ]);

        $crudo = (string) DB::table('notas_profesionales')->value('contenido');

        $this->assertStringNotContainsString('violencia intrafamiliar', $crudo);
    }

    public function test_el_secreto_de_dos_factores_no_se_lee_en_la_base(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create();

        $secreto = app(\App\Domain\Accesos\Servicios\SegundoFactor::class)->preparar($cuenta);

        $crudo = (string) DB::table('users')
            ->where('id', $cuenta->id)
            ->value('dos_factores_secreto');

        // Quien lea un respaldo no debe poder generar los códigos de nadie.
        $this->assertStringNotContainsString($secreto, $crudo);
    }

    public function test_el_token_de_aplicacion_se_guarda_hasheado_no_cifrado(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new \Tests\Apoyo\EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);

        $token = app(\App\Domain\Evaluaciones\Servicios\GestorTokens::class)->generar($destinatario);

        $guardado = (string) DB::table('asignacion_destinatarios')
            ->where('id', $destinatario->id)
            ->value('token');

        /*
         * HASHEADO y no cifrado, a propósito. Un token cifrado se puede
         * descifrar: quien tuviera la llave podría contestar en nombre de
         * cualquiera. Un hash no se revierte, y para verificar basta comparar.
         */
        $this->assertSame(hash('sha256', $token), $guardado);
    }
}
