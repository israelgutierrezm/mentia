<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Jobs\CalificarAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Los instrumentos oficiales de `database/seeds/instrumentos` (Doc 08, Fase 4).
 *
 * ESTA CLASE ES EL ARNÉS QUE ESPERA AL CONTENIDO. Recorre lo que haya en el
 * directorio y lo carga, lo publica y corre sus casos dorados. Mientras el
 * directorio esté vacío, las pruebas se saltan con un mensaje que dice por qué;
 * el día que alguien ponga el PHQ-9 revisado, la prueba de que califica bien ya
 * está escrita y corre sola.
 *
 * Los reactivos del PHQ-9, del M-CHAT-R/F y de las Guías de la NOM-035 no se
 * inventan: un instrumento sembrado con ítems aproximados produce puntajes que
 * parecen válidos y no lo son, y alguien tomaría decisiones clínicas con ellos.
 */
class InstrumentosSembradosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_comando_corre_aunque_no_haya_contenido(): void
    {
        if ($this->archivos() !== []) {
            $this->markTestSkipped('Ya hay instrumentos: este caso cubre el directorio vacío.');
        }

        /*
         * Que no haya contenido NO es un error: es el estado normal hasta que
         * llegue el material revisado. El comando lo dice en vez de fallar,
         * para que nadie concluya que está roto.
         */
        $this->artisan('mentia:seed-instrumentos', ['--directorio' => $this->directorio()])
            ->expectsOutputToContain('falta el contenido')
            ->assertSuccessful();
    }

    public function test_cada_instrumento_se_carga_y_se_publica(): void
    {
        $archivos = $this->archivos();

        if ($archivos === []) {
            $this->marcarPendienteDeContenido();
        }

        $this->sembrar()
            ->assertSuccessful();

        foreach (array_keys($archivos) as $clave) {
            $instrumento = Instrumento::query()->where('clave', $clave)->first();

            $this->assertNotNull($instrumento, sprintf('No se creó el instrumento «%s».', $clave));

            /*
             * Publicar valida que haya reactivos, que ninguna escala quede sin
             * claves y que las fórmulas citen escalas existentes. Un
             * instrumento que se queda en borrador está cargado pero no es
             * asignable, que es donde debe quedarse hasta que alguien lo
             * arregle.
             */
            $this->assertTrue(
                VersionInstrumento::query()
                    ->where('instrumento_id', $instrumento->id)
                    ->where('estado', VersionInstrumento::PUBLICADA)
                    ->exists(),
                sprintf('La versión de «%s» no se pudo publicar.', $clave),
            );
        }
    }

    public function test_sembrar_dos_veces_no_duplica(): void
    {
        if ($this->archivos() === []) {
            $this->marcarPendienteDeContenido();
        }

        $this->sembrar()->assertSuccessful();
        $antes = Instrumento::query()->count();

        $this->sembrar()->assertSuccessful();

        // Idempotente: una versión publicada está congelada (principio P4) y
        // volver a sembrarla encima sería justo lo que la inmutabilidad impide.
        $this->assertSame($antes, Instrumento::query()->count());
    }

    public function test_los_casos_dorados_dan_el_resultado_esperado(): void
    {
        $casos = $this->casosDorados();

        if ($casos === []) {
            $this->marcarPendienteDeContenido();
        }

        $this->sembrar()->assertSuccessful();

        foreach ($casos as $clave => $juegos) {
            foreach ($juegos as $caso) {
                $this->comprobarCaso($clave, $caso);
            }
        }
    }

    /** Corre el comando contra el directorio de esta clase. */
    private function sembrar(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('mentia:seed-instrumentos', [
            '--publicar' => true,
            '--directorio' => $this->directorio(),
        ]);
    }

    // ── Andamio ───────────────────────────────────────────────────────────

    /**
     * Aplica un juego de respuestas conocido y compara con lo esperado.
     *
     * @param  array{nombre?: string, respuestas: array<string, float>, esperado: array<string, array<string, mixed>>}  $caso
     */
    private function comprobarCaso(string $clave, array $caso): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $version = VersionInstrumento::query()
            ->whereHas('instrumento', fn ($consulta) => $consulta->where('clave', $clave))
            ->where('estado', VersionInstrumento::PUBLICADA)
            ->firstOrFail();

        $escenario = new EscenarioAsignacion($tenant);
        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $destinatario = $asignacion->destinatarios()->with('persona')->first();
        $destinatario->setRelation('asignacion', $asignacion);

        $aplicacion = app(\App\Domain\Evaluaciones\Servicios\MotorAplicacion::class)
            ->iniciar($destinatario, $version);

        foreach ($caso['respuestas'] as $codigo => $valor) {
            $reactivo = Reactivo::query()
                ->where('version_instrumento_id', $version->id)
                ->where('codigo', $codigo)
                ->firstOrFail();

            $opcion = OpcionReactivo::query()
                ->where('reactivo_id', $reactivo->id)
                ->where('codigo', (string) $valor)
                ->first();

            Respuesta::query()->create([
                'aplicacion_id' => $aplicacion->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opcion?->id,
                'valor_numerico' => $valor,
                'uuid_cliente' => (string) Str::uuid(),
                'tiempo_respuesta_ms' => 3000,
                'respondida_en' => now(),
            ]);
        }

        $aplicacion->update(['estado' => 'completada', 'finalizada_en' => now()]);

        foreach (CalificarAplicacion::etapas($aplicacion->id) as $etapa) {
            /** @var object{handle: callable} $etapa */
            $etapa->handle();
        }

        foreach ($caso['esperado'] as $claveEscala => $esperado) {
            $escala = Escala::query()
                ->where('version_instrumento_id', $version->id)
                ->where('clave', $claveEscala)
                ->firstOrFail();

            $resultado = ResultadoEscala::query()
                ->where('aplicacion_id', $aplicacion->id)
                ->where('escala_id', $escala->id)
                ->first();

            $donde = sprintf('%s / %s / %s', $clave, $caso['nombre'] ?? 'caso', $claveEscala);

            $this->assertNotNull($resultado, 'Sin resultado en '.$donde);

            if (isset($esperado['bruto'])) {
                $this->assertSame((float) $esperado['bruto'], $resultado->puntaje_bruto, $donde);
            }

            if (isset($esperado['etiqueta'])) {
                $this->assertSame($esperado['etiqueta'], $resultado->etiqueta_norma, $donde);
            }

            if (isset($esperado['normalizado'])) {
                $this->assertSame(
                    (float) $esperado['normalizado'],
                    $resultado->valor_normalizado,
                    $donde,
                );
            }
        }
    }

    /**
     * De dónde salen los archivos de datos.
     *
     * La subclase que prueba el propio arnés lo apunta a un juego sintético:
     * andamio sin probar se rompe justo el día que llega el contenido.
     */
    protected function directorio(): string
    {
        return base_path('database/seeds/instrumentos');
    }

    /**
     * @return array<string, string>
     */
    private function archivos(): array
    {
        $directorio = $this->directorio();
        $archivos = [];

        foreach ((array) glob($directorio.'/*.php') as $ruta) {
            if (! is_string($ruta)) {
                continue;
            }

            $clave = basename($ruta, '.php');

            if (str_ends_with($clave, '-casos')) {
                continue;
            }

            $archivos[$clave] = $ruta;
        }

        return $archivos;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function casosDorados(): array
    {
        $casos = [];

        foreach ($this->archivos() as $clave => $ruta) {
            $rutaCasos = dirname($ruta).'/'.$clave.'-casos.php';

            if (! is_file($rutaCasos)) {
                continue;
            }

            $contenido = require $rutaCasos;

            if (is_array($contenido) && $contenido !== []) {
                /** @var list<array<string, mixed>> $contenido */
                $casos[$clave] = $contenido;
            }
        }

        return $casos;
    }

    private function marcarPendienteDeContenido(): never
    {
        $this->markTestSkipped(
            'No hay archivos de datos de instrumentos. La maquinaria está lista; '
            .'falta el contenido revisado (ver database/seeds/instrumentos/LEEME.md).'
        );
    }
}
