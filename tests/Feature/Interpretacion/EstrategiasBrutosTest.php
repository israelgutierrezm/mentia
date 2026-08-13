<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Interpretacion\Servicios\EvaluadorFormulas;
use App\Domain\Interpretacion\Servicios\RegistroEstrategias;
use App\Jobs\Calificacion\EtapaBrutos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Casos dorados de las estrategias que no se resuelven sumando: ipsativos,
 * ranking, aciertos y conteo de criterio.
 *
 * Son las cuatro que un instrumento de la Ola 1 necesita y que un `suma_simple`
 * no puede imitar.
 */
class EstrategiasBrutosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_conteo_ipsativo_reparte_el_mas_y_el_menos(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $instrumento = $escenario->instrumento;
        $instrumento->escala('D');
        $instrumento->escala('I');

        $reactivo = $instrumento->reactivo(
            'eleccion_forzada_cuadro',
            'D',
            ['Decidido', 'Sociable'],
        );

        $opciones = OpcionReactivo::query()
            ->where('reactivo_id', $reactivo->id)
            ->orderBy('orden')
            ->get();

        // Se rehacen las claves: la misma opción puntúa a una escala como
        // «más» y a otra como «menos», que es lo que hace ipsativo al cuadro.
        ClaveCalificacion::query()->where('reactivo_id', $reactivo->id)->delete();

        foreach (['mas' => 'D', 'menos' => 'I'] as $rol => $claveEscala) {
            ClaveCalificacion::query()->create([
                'version_instrumento_id' => $instrumento->version->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opciones[0]->id,
                'escala_id' => $instrumento->escalas[$claveEscala]->id,
                'peso' => 1,
                'rol' => $rol,
            ]);
        }

        $escenario->pipeline('brutos', 'conteo_ipsativo');
        $escenario->contestar([]);

        // Se marca la primera opción como la que MÁS describe.
        Respuesta::query()->create([
            'aplicacion_id' => $escenario->aplicacion->id,
            'reactivo_id' => $reactivo->id,
            'opcion_id' => $opciones[0]->id,
            'rol_ipsativo' => 'mas',
            'uuid_cliente' => (string) Str::uuid(),
            'respondida_en' => Carbon::now(),
        ]);

        (new EtapaBrutos($escenario->aplicacion->id))->handle();

        // Suma a D por el rol «mas», y NADA a I: esa clave es la de «menos» y
        // la opción no se marcó así.
        $this->assertSame(1.0, $escenario->brutoDe('D'));
        $this->assertSame(0.0, $escenario->brutoDe('I'));
    }

    public function test_el_ranking_convierte_la_posicion_en_puntos(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $instrumento = $escenario->instrumento;

        $reactivo = $instrumento->reactivo(
            'ranking',
            'VAL',
            ['Uno', 'Dos', 'Tres', 'Cuatro'],
        );

        // Peso 1 para todas: lo que puntúa es la posición, no la opción.
        ClaveCalificacion::query()
            ->where('reactivo_id', $reactivo->id)
            ->update(['peso' => 1]);

        $opciones = OpcionReactivo::query()
            ->where('reactivo_id', $reactivo->id)
            ->orderBy('orden')
            ->get();

        $escenario->pipeline('brutos', 'ranking_ponderado');
        $escenario->contestar([]);

        foreach ($opciones as $posicion => $opcion) {
            Respuesta::query()->create([
                'aplicacion_id' => $escenario->aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opcion->id,
                'posicion_ranking' => $posicion + 1,
                'uuid_cliente' => (string) Str::uuid(),
                'respondida_en' => Carbon::now(),
            ]);
        }

        (new EtapaBrutos($escenario->aplicacion->id))->handle();

        // Cuatro opciones: 4 + 3 + 2 + 1 = 10.
        $this->assertSame(10.0, $escenario->brutoDe('VAL'));
    }

    public function test_el_conteo_de_correctas_cuenta_aciertos(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $instrumento = $escenario->instrumento;

        $reactivos = [];

        foreach (range(1, 3) as $ignorado) {
            $reactivo = $instrumento->reactivo(
                'opcion_multiple_correcta',
                'RAZ',
                ['Correcta', 'Incorrecta'],
            );

            OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->orderBy('orden')
                ->first()
                ?->update(['es_correcta' => true]);

            $reactivos[] = $reactivo;
        }

        $escenario->pipeline('brutos', 'conteo_correctas');
        $escenario->contestar([]);

        // Dos aciertos y un error.
        foreach ($reactivos as $indice => $reactivo) {
            $opciones = OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->orderBy('orden')
                ->get();

            Respuesta::query()->create([
                'aplicacion_id' => $escenario->aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $indice < 2 ? $opciones[0]->id : $opciones[1]->id,
                'uuid_cliente' => (string) Str::uuid(),
                'respondida_en' => Carbon::now(),
            ]);
        }

        (new EtapaBrutos($escenario->aplicacion->id))->handle();

        $this->assertSame(2.0, $escenario->brutoDe('RAZ'));
    }

    public function test_la_correccion_por_adivinanza_descuenta_los_errores(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $instrumento = $escenario->instrumento;
        $reactivos = [];

        foreach (range(1, 4) as $ignorado) {
            $reactivo = $instrumento->reactivo(
                'opcion_multiple_correcta',
                'RAZ',
                ['A', 'B', 'C'],
            );

            OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->orderBy('orden')
                ->first()
                ?->update(['es_correcta' => true]);

            $reactivos[] = $reactivo;
        }

        $escenario->pipeline('brutos', 'conteo_correctas', ['correccion_adivinanza' => 1]);
        $escenario->contestar([]);

        // Tres aciertos y un error, con tres opciones: 3 − 1/(3−1) = 2.5.
        foreach ($reactivos as $indice => $reactivo) {
            $opciones = OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->orderBy('orden')
                ->get();

            Respuesta::query()->create([
                'aplicacion_id' => $escenario->aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $indice < 3 ? $opciones[0]->id : $opciones[1]->id,
                'uuid_cliente' => (string) Str::uuid(),
                'respondida_en' => Carbon::now(),
            ]);
        }

        (new EtapaBrutos($escenario->aplicacion->id))->handle();

        $this->assertSame(2.5, $escenario->brutoDe('RAZ'));
    }

    public function test_el_conteo_de_criterio_cuenta_uno_por_reactivo_en_riesgo(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        // Cuatro reactivos dicotómicos; la dirección de riesgo la marca el peso.
        $escenario->reactivoDeSuma('RIESGO', [0, 1]);
        $escenario->reactivoDeSuma('RIESGO', [0, 1]);
        $escenario->reactivoDeSuma('RIESGO', [0, 1]);
        $escenario->reactivoDeSuma('RIESGO', [0, 1]);

        $escenario->pipeline('brutos', 'conteo_criterio');

        $escenario->contestar([1, 1, 0, 1]);
        (new EtapaBrutos($escenario->aplicacion->id))->handle();

        /*
         * Tres en riesgo. El conteo suma UNO por reactivo que cumple, no el
         * valor: en un M-CHAT el puntaje es cuántos salieron en la dirección de
         * riesgo, no la suma de las respuestas.
         */
        $this->assertSame(3.0, $escenario->brutoDe('RIESGO'));
    }

    // ── El evaluador de fórmulas ──────────────────────────────────────────

    public function test_el_evaluador_de_formulas_respeta_la_precedencia(): void
    {
        $evaluador = app(EvaluadorFormulas::class);

        $this->assertSame(7.0, $evaluador->evaluar('A + B * 2', ['A' => 1.0, 'B' => 3.0]));
        $this->assertSame(8.0, $evaluador->evaluar('(A + B) * 2', ['A' => 1.0, 'B' => 3.0]));
        $this->assertSame(-2.0, $evaluador->evaluar('A - B', ['A' => 1.0, 'B' => 3.0]));
        $this->assertSame(-1.0, $evaluador->evaluar('-A', ['A' => 1.0]));
    }

    public function test_el_evaluador_de_formulas_no_ejecuta_codigo(): void
    {
        $evaluador = app(EvaluadorFormulas::class);

        /*
         * Una expresión que llegó de una hoja de Excel subida por un tenant,
         * ejecutándose como PHP, es ejecución remota de código con los pasos
         * intermedios ya hechos. La gramática no admite nada que no sea
         * aritmética.
         */
        $this->expectExceptionMessageMatches('/no se admite|no tiene puntaje/');

        $evaluador->evaluar('phpinfo()', ['A' => 1.0]);
    }

    // ── El registro de estrategias ────────────────────────────────────────

    public function test_una_estrategia_desconocida_falla_ruidoso(): void
    {
        $registro = app(RegistroEstrategias::class);

        $this->expectExceptionMessageMatches('/no hay estrategia/i');

        $registro->resolver('inventada_por_alguien');
    }

    public function test_una_estrategia_en_la_etapa_equivocada_no_corre(): void
    {
        $registro = app(RegistroEstrategias::class);

        /*
         * Una estrategia de brutos configurada en la etapa de normalización no
         * "hace algo raro": corre con un contexto que no tiene lo que espera y
         * produce un número. Mejor que no arranque.
         */
        $this->expectExceptionMessageMatches('/es de la etapa/');

        $registro->resolverParaEtapa('suma_simple', 'normalizacion');
    }
}
