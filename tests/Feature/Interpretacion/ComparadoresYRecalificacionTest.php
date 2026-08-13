<?php

declare(strict_types=1);

namespace Tests\Feature\Interpretacion;

use App\Domain\Interpretacion\Modelos\PerfilPuesto;
use App\Domain\Interpretacion\Modelos\PerfilPuestoCriterio;
use App\Domain\Interpretacion\Modelos\ResultadoArchivado;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Servicios\ArchivadorResultados;
use App\Domain\Interpretacion\Servicios\ComparadorPuesto;
use App\Domain\Interpretacion\Servicios\DetectorCambioSignificativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Comparadores del Doc 05 §4 y el histórico de recalificación.
 */
class ComparadoresYRecalificacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ── Persona contra puesto ─────────────────────────────────────────────

    public function test_el_ajuste_al_puesto_pondera_los_criterios(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('ESCRUP');
        $escenario->reactivoDeSuma('EXTRO');
        $escenario->pipeline('brutos', 'suma_simple');

        $escenario->contestar([3, 0]);
        $escenario->calificar();

        $perfil = PerfilPuesto::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'nombre' => 'Cajero',
        ]);

        // La escrupulosidad pesa cuatro veces más que la extroversión.
        PerfilPuestoCriterio::query()->create([
            'perfil_puesto_id' => $perfil->id,
            'escala_id' => $escenario->instrumento->escalas['ESCRUP']->id,
            'tipo_puntaje' => 'bruto',
            'valor_min' => 2,
            'ponderacion' => 4,
        ]);

        PerfilPuestoCriterio::query()->create([
            'perfil_puesto_id' => $perfil->id,
            'escala_id' => $escenario->instrumento->escalas['EXTRO']->id,
            'tipo_puntaje' => 'bruto',
            'valor_min' => 2,
            'ponderacion' => 1,
        ]);

        $ajuste = app(ComparadorPuesto::class)->comparar($perfil, [$escenario->aplicacion]);

        /*
         * Cumple el criterio que pesa 4 y falla el que pesa 1: 80%. Un promedio
         * plano diría 50% y pondría al mismo nivel a un candidato escrupuloso
         * poco sociable y a uno sociable descuidado, que para una caja no son
         * lo mismo.
         */
        $this->assertSame(80.0, $ajuste['ajuste_pct']);
        $this->assertSame(0, $ajuste['sin_dato']);
    }

    public function test_un_criterio_sin_dato_no_cuenta_como_fallo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('ESCRUP');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([3]);
        $escenario->calificar();

        // Una escala que existe en el catálogo pero que este candidato no
        // contestó porque nadie le aplicó ese instrumento.
        $otraEscala = $escenario->instrumento->escala('LIDER');

        $perfil = PerfilPuesto::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'nombre' => 'Supervisor',
        ]);

        PerfilPuestoCriterio::query()->create([
            'perfil_puesto_id' => $perfil->id,
            'escala_id' => $escenario->instrumento->escalas['ESCRUP']->id,
            'tipo_puntaje' => 'bruto',
            'valor_min' => 2,
            'ponderacion' => 1,
        ]);

        PerfilPuestoCriterio::query()->create([
            'perfil_puesto_id' => $perfil->id,
            'escala_id' => $otraEscala->id,
            'tipo_puntaje' => 'percentil',
            'valor_min' => 60,
            'ponderacion' => 1,
        ]);

        $ajuste = app(ComparadorPuesto::class)->comparar($perfil, [$escenario->aplicacion]);

        /*
         * El criterio sin dato se reporta aparte, no como fallo: contarlo como
         * cero hundiría al candidato por algo que nadie le preguntó.
         */
        $this->assertSame(100.0, $ajuste['ajuste_pct']);
        $this->assertSame(1, $ajuste['sin_dato']);
    }

    // ── Persona contra sí misma ───────────────────────────────────────────

    public function test_solo_se_marca_el_cambio_que_sale_del_error_de_medida(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('ANS');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([1]);
        $escenario->calificar();

        $persona = $escenario->aplicacion->persona;

        $puntos = [
            ['2024-01-15', 40.0],
            ['2024-07-15', 45.0],  // +5: ruido de medición.
            ['2025-01-15', 78.0],  // +33: eso sí es un cambio.
        ];

        ResultadoNormalizado::query()->where('persona_id', $persona->id)->delete();

        foreach ($puntos as [$fecha, $valor]) {
            ResultadoNormalizado::query()->create([
                'persona_id' => $persona->id,
                'dominio_id' => $escenario->instrumento->instrumento->dominio_id,
                'constructo' => 'ANS',
                'version_instrumento_id' => $escenario->instrumento->version->id,
                'aplicacion_id' => $escenario->aplicacion->id,
                'organizacion_id_contexto' => $tenant->organizacion->id,
                'fecha' => $fecha,
                'tipo_norma' => 'percentil',
                'valor' => $valor,
            ]);
        }

        $cambios = app(DetectorCambioSignificativo::class)->serieDe($persona->id, 'ANS');

        $this->assertCount(2, $cambios);
        $this->assertFalse($cambios[0]['significativo']);
        $this->assertTrue($cambios[1]['significativo']);
        $this->assertSame('sube', $cambios[1]['direccion']);
    }

    public function test_no_se_comparan_dos_normas_distintas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('ANS');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([1]);
        $escenario->calificar();

        $persona = $escenario->aplicacion->persona;

        ResultadoNormalizado::query()->where('persona_id', $persona->id)->delete();

        foreach ([['2024-01-15', 'percentil', 40.0], ['2025-01-15', 'T', 55.0]] as [$fecha, $norma, $valor]) {
            ResultadoNormalizado::query()->create([
                'persona_id' => $persona->id,
                'dominio_id' => $escenario->instrumento->instrumento->dominio_id,
                'constructo' => 'ANS',
                'version_instrumento_id' => $escenario->instrumento->version->id,
                'aplicacion_id' => $escenario->aplicacion->id,
                'organizacion_id_contexto' => $tenant->organizacion->id,
                'fecha' => $fecha,
                'tipo_norma' => $norma,
                'valor' => $valor,
            ]);
        }

        /*
         * Un percentil y una T no son la misma regla. Restar 40 de 55 daría 15
         * "puntos de mejora" que no describen nada de la persona.
         */
        $this->assertSame([], app(DetectorCambioSignificativo::class)->serieDe($persona->id, 'ANS'));
    }

    // ── Recalificación ────────────────────────────────────────────────────

    public function test_recalificar_conserva_el_resultado_anterior(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('RAZ');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->baremo('RAZ', [[0, 9, 30, 'bajo']]);

        $escenario->contestar([3]);
        $escenario->calificar();

        $this->assertSame(30.0, $escenario->resultadoDe('RAZ')->valor_normalizado);

        // Se descubre que el baremo estaba mal y se corrige.
        app(ArchivadorResultados::class)->archivar(
            $escenario->aplicacion,
            'Baremo corregido'
        );

        \App\Domain\Catalogo\Modelos\BaremoFila::query()->update(['valor_normalizado' => 80]);

        $escenario->calificar();

        $this->assertSame(80.0, $escenario->resultadoDe('RAZ')->valor_normalizado);

        /*
         * Y el anterior sigue ahí. Es lo que se le entregó a alguien —quizá lo
         * que sustentó una decisión— y una impugnación de hace seis meses tiene
         * que poder reconstruirse.
         */
        $archivo = ResultadoArchivado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->with('escalas')
            ->first();

        $this->assertNotNull($archivo);
        $this->assertSame('Baremo corregido', $archivo->motivo);

        $archivada = $archivo->escalas->firstWhere('escala_clave', 'RAZ');

        $this->assertNotNull($archivada);
        $this->assertSame(30.0, $archivada->valor_normalizado);
        $this->assertSame(3.0, $archivada->puntaje_bruto);
    }

    public function test_recalificar_no_agrega_puntos_a_la_serie(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('RAZ');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->baremo('RAZ', [[0, 9, 30, 'bajo']]);

        $escenario->contestar([3]);
        $escenario->calificar();
        $escenario->calificar();
        $escenario->calificar();

        /*
         * Tres puntos el mismo día por el mismo instrumento serían un cambio
         * que nunca ocurrió, y la gráfica evolutiva lo dibujaría como si sí.
         */
        $this->assertSame(1, ResultadoNormalizado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->count());
    }

    public function test_el_comando_de_recalificacion_exige_criterio(): void
    {
        /*
         * Una recalificación masiva sin querer sobre cien mil aplicaciones es
         * una tarde de cola y un montón de expedientes tocados sin razón.
         */
        $this->artisan('mentia:recalificar')
            ->expectsOutputToContain('Hay que decir qué recalificar')
            ->assertSuccessful();
    }

    public function test_el_comando_recalifica_una_aplicacion_concreta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioCalificacion($tenant);

        $escenario->reactivoDeSuma('RAZ');
        $escenario->pipeline('brutos', 'suma_simple');
        $escenario->contestar([3]);
        $escenario->calificar();

        Carbon::setTestNow(Carbon::now()->addMinute());

        $this->artisan('mentia:recalificar', [
            '--aplicacion' => $escenario->aplicacion->uuid,
            '--ahora' => true,
        ])->assertSuccessful();

        Carbon::setTestNow();

        $this->assertSame(1, ResultadoArchivado::query()
            ->where('aplicacion_id', $escenario->aplicacion->id)
            ->count());
    }
}
