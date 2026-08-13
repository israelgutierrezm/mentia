<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Modelos\ValidezDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Casos dorados del pipeline (Doc 08, Fase 7).
 *
 * Un juego de respuestas conocido con un resultado esperado, para cada
 * estrategia. Es lo que permite que cargar el contenido real de un instrumento
 * en la Fase 4 sea sembrar datos y no escribir código: la prueba de que
 * califica bien ya está.
 */
class PipelineCalificacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ── Etapa 2: brutos ───────────────────────────────────────────────────

    public function test_suma_simple_suma_los_valores_de_las_respuestas(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->reactivoDeSuma('DEP');
        $escenario->reactivoDeSuma('DEP');

        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->contestar([3, 2, 1]);
        $escenario->calificar();

        $this->assertSame(6.0, $escenario->brutoDe('DEP'));
    }

    public function test_un_reactivo_inverso_se_refleja(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('BIEN');
        $escenario->reactivoDeSuma('BIEN', esInverso: true);

        $escenario->pipeline('brutos', 'suma_simple');

        /*
         * «No me cuesta trabajo concentrarme» puntúa al revés que «Me cuesta
         * trabajo concentrarme». Sin reflejar, los dos sumarían en la misma
         * dirección y la escala mediría ruido.
         *
         * Dominio 0–3: un 3 en el inverso vale (3+0) − 3 = 0.
         */
        $escenario->contestar([2, 3]);
        $escenario->calificar();

        $this->assertSame(2.0, $escenario->brutoDe('BIEN'));
    }

    public function test_una_escala_sin_respuestas_queda_en_cero_y_no_desaparece(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->instrumento->escala('ANS');

        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->contestar([2]);
        $escenario->calificar();

        /*
         * La diferencia entre "cero" y "no se calculó" desaparecería justo
         * donde importa: un reporte que omite una escala se lee como que el
         * instrumento no la mide.
         */
        $this->assertSame(0.0, $escenario->brutoDe('ANS'));
    }

    public function test_las_formulas_derivadas_se_evaluan_en_su_orden(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('M');
        $escenario->reactivoDeSuma('L');

        $escenario->pipeline('brutos', 'suma_simple');

        // El T del Cleaver es M − L, y el índice compuesto lo usa a su vez.
        $escenario->formula('T', 'M - L', orden: 1);
        $escenario->formula('IDX', '(T + 10) * 2', orden: 2);

        $escenario->contestar([3, 1]);
        $escenario->calificar();

        $this->assertSame(2.0, $escenario->brutoDe('T'));

        /*
         * Si el orden no se respetara, IDX se calcularía con T todavía en cero
         * y daría 20 sin quejarse de nada.
         */
        $this->assertSame(24.0, $escenario->brutoDe('IDX'));
    }

    // ── Etapa 1: validez ──────────────────────────────────────────────────

    public function test_demasiadas_omisiones_invalidan_y_detienen_el_pipeline(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        foreach (range(1, 5) as $ignorado) {
            $escenario->reactivoDeSuma('DEP');
        }

        $escenario->pipeline('validez', 'omisiones_max', ['umbral_pct' => 20]);
        $escenario->pipeline('brutos', 'suma_simple');

        // Se contesta uno de cinco: 80% de omisión.
        $escenario->contestar([3]);
        $escenario->calificar();

        $this->assertSame('invalida', $escenario->aplicacion->validez);

        /*
         * Y NO SE CALIFICA. Un protocolo con el 80% en blanco produce un
         * puntaje bajo perfectamente calculable, y a esa altura ya nadie lo
         * distingue de un puntaje bajo de verdad.
         */
        $this->assertNull($escenario->brutoDe('DEP'));
    }

    public function test_el_straight_lining_deja_la_aplicacion_dudosa_pero_la_califica(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        foreach (range(1, 10) as $ignorado) {
            $escenario->reactivoDeSuma('DEP');
        }

        $escenario->pipeline('validez', 'patron_repetido', ['consecutivas_max' => 8]);
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->contestar(array_fill(0, 10, 2.0));
        $escenario->calificar();

        $this->assertSame('dudosa', $escenario->aplicacion->validez);

        // Dudosa SIGUE: el puntaje existe y el reporte lo dice.
        $this->assertSame(20.0, $escenario->brutoDe('DEP'));

        $detalle = ValidezDetalle::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->where('verificacion', 'patron_repetido')
            ->first();

        $this->assertNotNull($detalle);
        $this->assertSame('advertencia', $detalle->resultado);
    }

    public function test_responder_demasiado_rapido_levanta_advertencia(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        foreach (range(1, 6) as $ignorado) {
            $escenario->reactivoDeSuma('DEP');
        }

        $escenario->pipeline('validez', 'tiempo_atipico', ['ms_min' => 800]);
        $escenario->pipeline('brutos', 'suma_simple');

        // 200 ms por reactivo: nadie lee un enunciado en ese tiempo.
        $escenario->contestar([1.0, 2.0, 3.0, 0.0, 1.0, 2.0], tiempoMs: 200);
        $escenario->calificar();

        $this->assertSame('dudosa', $escenario->aplicacion->validez);
    }

    // ── Etapa 3: algoritmos especiales ────────────────────────────────────

    public function test_los_cortes_del_phq_clasifican_la_gravedad(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        foreach (range(1, 9) as $ignorado) {
            $escenario->reactivoDeSuma('PHQ');
        }

        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->pipeline('algoritmos', 'phq_gravedad', ['escala' => 'PHQ']);

        // Nueve reactivos en 2 = 18: moderadamente grave (15–19).
        $escenario->contestar(array_fill(0, 9, 2.0));
        $escenario->calificar();

        $resultado = $escenario->resultadoDe('PHQ');

        $this->assertSame(18.0, $resultado->puntaje_bruto);
        $this->assertSame('moderadamente_grave', $resultado->etiqueta_norma);
        $this->assertSame('semaforo', $resultado->tipo_norma);
    }

    public function test_el_mchat_de_riesgo_medio_no_concluye_sin_la_entrevista(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        foreach (range(1, 6) as $ignorado) {
            $escenario->reactivoDeSuma('TOTAL', [0, 1]);
        }

        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->pipeline('algoritmos', 'mchat_dos_etapas', [
            'escala' => 'TOTAL',
            'escala_seguimiento' => 'SEG',
        ]);

        $escenario->instrumento->escala('SEG');

        // Cuatro de seis en riesgo: cae en la banda media (3–7).
        $escenario->contestar([1, 1, 1, 1, 0, 0]);
        $escenario->calificar();

        /*
         * La mitad de los que caen en la banda media bajan a riesgo bajo tras
         * la entrevista de seguimiento. Tratar el 4 inicial como resultado
         * final mandaría a evaluación especializada a familias que no la
         * necesitan.
         */
        $this->assertSame(
            'riesgo_medio_pendiente_entrevista',
            $escenario->resultadoDe('TOTAL')->etiqueta_norma
        );
    }

    // ── Etapa 4: normalización ────────────────────────────────────────────

    public function test_el_baremo_convierte_el_bruto_en_percentil(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('RAZ');
        $escenario->reactivoDeSuma('RAZ');

        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->baremo('RAZ', [
            [0, 2, 25, 'bajo'],
            [3, 4, 60, 'medio'],
            [5, 6, 90, 'alto'],
        ]);

        $escenario->contestar([2, 2]);
        $escenario->calificar();

        $resultado = $escenario->resultadoDe('RAZ');

        $this->assertSame(4.0, $resultado->puntaje_bruto);
        $this->assertSame(60.0, $resultado->valor_normalizado);
        $this->assertSame('percentil', $resultado->tipo_norma);
        $this->assertFalse($resultado->sin_norma);
    }

    public function test_el_baremo_del_tenant_le_gana_al_global(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('RAZ');
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->baremo('RAZ', [[0, 9, 50, 'medio']]);
        $escenario->baremo(
            'RAZ',
            [[0, 9, 88, 'alto']],
            organizacionId: $tenant->organizacion->id,
        );

        $escenario->contestar([3]);
        $escenario->calificar();

        /*
         * Una empresa con diez mil aplicaciones propias tiene mejor norma para
         * su gente que la tabla publicada en un manual de 1998 con
         * universitarios de otro país.
         */
        $this->assertSame(88.0, $escenario->resultadoDe('RAZ')?->valor_normalizado);
    }

    public function test_sin_baremo_aplicable_se_marca_sin_norma_y_no_se_inventa_percentil(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('RAZ');
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->contestar([3]);
        $escenario->calificar();

        $resultado = $escenario->resultadoDe('RAZ');

        $this->assertTrue($resultado->sin_norma);
        $this->assertNull($resultado->valor_normalizado);

        // Y no entra a la serie longitudinal: un bruto sin norma dibujado
        // junto a percentiles produce una gráfica que sube por cambiar de
        // prueba, no por cambiar la persona.
        $this->assertSame(0, ResultadoNormalizado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->count());
    }

    public function test_la_normalizacion_no_pisa_lo_que_clasifico_un_algoritmo(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('PHQ');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->pipeline('algoritmos', 'phq_gravedad', ['escala' => 'PHQ']);

        // Hay un baremo de percentiles que podría aplicar.
        $escenario->baremo('PHQ', [[0, 9, 42, 'medio']]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $resultado = $escenario->resultadoDe('PHQ');

        /*
         * Los cortes del PHQ-9 vienen de su manual. Re-normalizarlos contra
         * una tabla de percentiles produciría una categoría que se ve como la
         * del instrumento y no lo es.
         */
        $this->assertSame('semaforo', $resultado->tipo_norma);
        $this->assertSame('minima', $resultado->etiqueta_norma);
    }

    // ── Etapa 5 y 6: interpretación y banderas ────────────────────────────

    public function test_la_interpretacion_resuelve_sus_variables_por_audiencia(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->regla('DEP', 'Puntaje de {puntaje} en {instrumento}.', [
            'operador' => '>=',
            'valor_min' => 2,
            'audiencia' => 'profesional',
            'bandera' => 'amarillo',
        ]);

        $escenario->regla('DEP', 'Contestaste el {instrumento} el {fecha}.', [
            'operador' => '>=',
            'valor_min' => 2,
            'audiencia' => 'evaluado_adulto',
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $delProfesional = ResultadoInterpretacion::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->paraAudiencia('profesional')
            ->first();

        $this->assertStringContainsString('Puntaje de 3', (string) $delProfesional?->texto_resuelto);

        $delEvaluado = ResultadoInterpretacion::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->paraAudiencia('evaluado_adulto')
            ->first();

        $this->assertNotNull($delEvaluado);
        $this->assertStringNotContainsString('{', (string) $delEvaluado->texto_resuelto);
    }

    public function test_una_regla_que_no_se_cumple_no_produce_texto(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->regla('DEP', 'Nivel elevado.', ['operador' => '>=', 'valor_min' => 20]);

        $escenario->contestar([1]);
        $escenario->calificar();

        $this->assertSame(0, ResultadoInterpretacion::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->count());
    }

    public function test_la_bandera_llega_a_la_serie_longitudinal(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->baremo('DEP', [[0, 9, 75, 'alto']]);

        $escenario->regla('DEP', 'Nivel elevado.', [
            'operador' => '>=',
            'valor_min' => 2,
            'bandera' => 'rojo',
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $punto = ResultadoNormalizado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->where('constructo', 'DEP')
            ->first();

        $this->assertNotNull($punto);
        $this->assertSame(75.0, $punto->valor);

        // Es lo que permite pintar en rojo el punto de hace tres años sin
        // volver a interpretar nada.
        $this->assertSame('rojo', $punto->bandera);
    }

    public function test_ante_dos_banderas_gana_la_mas_grave(): void
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());

        $escenario->reactivoDeSuma('DEP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->baremo('DEP', [[0, 9, 75, 'alto']]);

        $escenario->regla('DEP', 'Dentro de lo esperado.', [
            'operador' => '>=',
            'valor_min' => 0,
            'bandera' => 'verde',
            'prioridad' => 1,
        ]);

        $escenario->regla('DEP', 'Atención inmediata.', [
            'operador' => '>=',
            'valor_min' => 2,
            'bandera' => 'rojo',
            'prioridad' => 9,
        ]);

        $escenario->contestar([3]);
        $escenario->calificar();

        /*
         * Quedarse con la última en llegar dejaría que una regla verde de
         * prioridad baja tapara la roja, y el rojo es justamente lo que nadie
         * puede perderse.
         */
        $this->assertSame('rojo', ResultadoNormalizado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->value('bandera'));
    }
}
