<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Catalogo\Modelos\Baremo;
use App\Domain\Catalogo\Modelos\BaremoFila;
use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\EtapaPipeline;
use App\Domain\Catalogo\Modelos\FormulaDerivada;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\ParametroPipeline;
use App\Domain\Catalogo\Modelos\PoblacionNorma;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\ReglaInterpretacion;
use App\Domain\Catalogo\Modelos\TipoReactivo;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Jobs\Calificacion\EtapaAlgoritmos;
use App\Jobs\Calificacion\EtapaBanderas;
use App\Jobs\Calificacion\EtapaBrutos;
use App\Jobs\Calificacion\EtapaInterpretacion;
use App\Jobs\Calificacion\EtapaNormalizacion;
use App\Jobs\Calificacion\EtapaValidez;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Arnés de CASOS DORADOS: un instrumento con su pipeline configurado, un juego
 * de respuestas y un resultado esperado.
 *
 * Es lo que el Doc 08 pide para la Fase 7, y lo que hace que la Fase 4 sea
 * sembrar contenido en vez de escribir código: cuando lleguen los reactivos
 * reales del PHQ-9, la prueba de que califica bien ya está escrita.
 *
 * Corre las etapas SÍNCRONAS, en orden. No se prueba la cola aquí: lo que se
 * prueba es la aritmética, y meter un worker en medio sólo agregaría formas de
 * fallar que no tienen que ver con el resultado.
 */
class EscenarioCalificacion
{
    public InstrumentoSintetico $instrumento;

    public Aplicacion $aplicacion;

    /**
     * Los reactivos que ESTE escenario creó, en orden.
     *
     * El instrumento base trae ya un reactivo suyo —hace falta para poder
     * publicar la versión—, así que los códigos vienen corridos. Se responde
     * por posición para que una prueba no dependa de cuántos reactivos trajo
     * el andamio.
     *
     * @var list<Reactivo>
     */
    public array $reactivos = [];

    private EscenarioAplicacion $base;

    public function __construct(public EscenarioTenant $tenant)
    {
        $this->base = new EscenarioAplicacion($tenant);
        $this->instrumento = $this->base->asignacion->instrumento;
    }

    /**
     * Un reactivo likert cuyo VALOR suma a la escala (`suma_simple`).
     *
     * @param  list<int>  $valores  Los códigos numéricos de las opciones.
     */
    public function reactivoDeSuma(
        string $claveEscala,
        array $valores = [0, 1, 2, 3],
        bool $esInverso = false,
    ): Reactivo {
        $escala = $this->instrumento->escalas[$claveEscala]
            ?? $this->instrumento->escala($claveEscala);

        $orden = Reactivo::query()
            ->where('version_instrumento_id', $this->instrumento->version->id)
            ->count() + 1;

        $reactivo = Reactivo::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'bloque_id' => $this->instrumento->bloque->id,
            'tipo_reactivo_id' => $this->tipoLikertDe(count($valores)),
            'codigo' => sprintf('R%03d', $orden),
            'enunciado' => 'Reactivo de suma '.$orden,
            'es_inverso' => $esInverso,
            'orden' => $orden,
        ]);

        foreach ($valores as $indice => $valor) {
            OpcionReactivo::query()->create([
                'reactivo_id' => $reactivo->id,
                'codigo' => (string) $valor,
                'texto' => 'Opción '.$valor,
                'orden' => $indice,
            ]);
        }

        // Clave SIN opción: "este reactivo pertenece a esta escala".
        ClaveCalificacion::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'reactivo_id' => $reactivo->id,
            'escala_id' => $escala->id,
            'peso' => 1,
        ]);

        $this->reactivos[] = $reactivo;

        return $reactivo;
    }

    /** El tipo likert que le corresponde a ese número de opciones. */
    private function tipoLikertDe(int $cuantas): int
    {
        $clave = in_array($cuantas, [3, 4, 5, 7], true) ? 'likert_'.$cuantas : 'likert_5';

        return (int) TipoReactivo::query()->where('clave', $clave)->value('id');
    }

    /**
     * Configura una etapa del pipeline.
     *
     * @param  array<string, string|int|float>  $parametros
     */
    public function pipeline(
        string $etapa,
        string $estrategia,
        array $parametros = [],
        int $orden = 0,
    ): EtapaPipeline {
        $fila = EtapaPipeline::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'etapa' => $etapa,
            'estrategia_clave' => $estrategia,
            'orden' => $orden,
        ]);

        foreach ($parametros as $clave => $valor) {
            ParametroPipeline::query()->create([
                'instrumento_pipeline_id' => $fila->id,
                'clave' => $clave,
                'valor' => (string) $valor,
            ]);
        }

        return $fila;
    }

    public function formula(string $claveDestino, string $expresion, int $orden = 0): FormulaDerivada
    {
        $escala = $this->instrumento->escalas[$claveDestino]
            ?? $this->instrumento->escala($claveDestino);

        return FormulaDerivada::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'escala_destino_id' => $escala->id,
            'expresion' => $expresion,
            'orden_evaluacion' => $orden,
        ]);
    }

    /**
     * Un baremo con sus filas.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3?: string}>  $filas
     *                                                                        Cada una: bruto_min, bruto_max, valor_normalizado, etiqueta.
     */
    public function baremo(
        string $claveEscala,
        array $filas,
        string $tipoNorma = 'percentil',
        ?int $organizacionId = null,
        string $pais = 'MX',
        ?int $edadMinMeses = null,
        ?int $edadMaxMeses = null,
    ): Baremo {
        $escala = $this->instrumento->escalas[$claveEscala]
            ?? $this->instrumento->escala($claveEscala);

        $poblacion = PoblacionNorma::query()->firstOrCreate(
            ['clave' => 'pob_'.$pais],
            ['nombre' => 'Población '.$pais, 'pais' => $pais],
        );

        $baremo = Baremo::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'escala_id' => $escala->id,
            'poblacion_norma_id' => $poblacion->id,
            'organizacion_id' => $organizacionId,
            'tipo_norma' => $tipoNorma,
            'vigente' => true,
        ]);

        foreach ($filas as $fila) {
            BaremoFila::query()->create([
                'baremo_id' => $baremo->id,
                'bruto_min' => $fila[0],
                'bruto_max' => $fila[1],
                'valor_normalizado' => $fila[2],
                'etiqueta' => $fila[3] ?? null,
                'edad_min_meses' => $edadMinMeses,
                'edad_max_meses' => $edadMaxMeses,
            ]);
        }

        return $baremo;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function regla(
        string $claveEscala,
        string $texto,
        array $extra = [],
    ): ReglaInterpretacion {
        $escala = $this->instrumento->escalas[$claveEscala]
            ?? $this->instrumento->escala($claveEscala);

        return ReglaInterpretacion::query()->create([
            'version_instrumento_id' => $this->instrumento->version->id,
            'escala_id' => $escala->id,
            'tipo_regla' => 'rango_escala',
            'tipo_puntaje' => 'bruto',
            'operador' => '>=',
            'valor_min' => 0,
            'audiencia' => 'profesional',
            'texto_interpretacion' => $texto,
            'prioridad' => 1,
            'vigente' => true,
            ...$extra,
        ]);
    }

    /**
     * Arranca la aplicación y contesta POR POSICIÓN.
     *
     * Cada valor corresponde al reactivo que se creó en ese orden; `null` es no
     * contestar, que es lo que la etapa de omisiones necesita poder expresar.
     *
     * @param  list<float|null>  $valores
     */
    public function contestar(array $valores, ?int $tiempoMs = 3000): Aplicacion
    {
        $this->aplicacion = $this->base->iniciar();

        foreach ($valores as $indice => $valor) {
            if ($valor === null || ! isset($this->reactivos[$indice])) {
                continue;
            }

            $reactivo = $this->reactivos[$indice];

            $opcion = OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->where('codigo', (string) $valor)
                ->first();

            Respuesta::query()->create([
                'aplicacion_id' => $this->aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opcion?->id,
                'valor_numerico' => $valor,
                'uuid_cliente' => (string) Str::uuid(),
                'tiempo_respuesta_ms' => $tiempoMs,
                'respondida_en' => Carbon::now(),
            ]);
        }

        $this->aplicacion->update([
            'estado' => 'completada',
            'finalizada_en' => Carbon::now(),
        ]);

        return $this->aplicacion->refresh();
    }

    /**
     * Corre las seis etapas en orden, síncronas.
     */
    public function calificar(): void
    {
        foreach ([
            EtapaValidez::class,
            EtapaBrutos::class,
            EtapaAlgoritmos::class,
            EtapaNormalizacion::class,
            EtapaInterpretacion::class,
            EtapaBanderas::class,
        ] as $etapa) {
            (new $etapa($this->aplicacion->id))->handle();
        }

        $this->aplicacion->refresh();
    }

    public function resultadoDe(string $claveEscala): ?ResultadoEscala
    {
        $escala = Escala::query()
            ->where('version_instrumento_id', $this->instrumento->version->id)
            ->where('clave', $claveEscala)
            ->first();

        if ($escala === null) {
            return null;
        }

        return ResultadoEscala::query()
            ->where('aplicacion_id', $this->aplicacion->id)
            ->where('escala_id', $escala->id)
            ->first();
    }

    public function brutoDe(string $claveEscala): ?float
    {
        return $this->resultadoDe($claveEscala)?->puntaje_bruto;
    }
}
