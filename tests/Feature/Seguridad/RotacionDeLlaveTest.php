<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Console\Commands\RotarLlaveDeCifrado;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Apoyo\EscenarioCalificacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Rotación de `APP_KEY`.
 *
 * Cambiar la llave sin recifrar deja todo lo cifrado ilegible PARA SIEMPRE: no
 * es un error que se arregle volviendo atrás, porque los datos siguen cifrados
 * con la llave vieja que ya nadie tiene.
 */
class RotacionDeLlaveTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_la_lista_del_comando_cubre_todos_los_casts_cifrados(): void
    {
        $declaradas = [];

        foreach (RotarLlaveDeCifrado::COLUMNAS_CIFRADAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $declaradas[] = $tabla.'.'.$columna;
            }
        }

        $encontradas = [];

        foreach ($this->modelosDelDominio() as $clase) {
            $modelo = new $clase;

            $metodo = (new ReflectionClass($modelo))->getMethod('casts');
            $metodo->setAccessible(true);

            /** @var array<string, string> $casts */
            $casts = $metodo->invoke($modelo);

            foreach ($casts as $atributo => $cast) {
                if (! str_starts_with((string) $cast, 'encrypted')) {
                    continue;
                }

                $encontradas[] = $modelo->getTable().'.'.$atributo;
            }
        }

        sort($declaradas);
        $encontradas = array_values(array_unique($encontradas));
        sort($encontradas);

        /*
         * Agregar un cast `encrypted` en un modelo y no agregarlo al comando
         * produce una rotación que parece exitosa y deja esa columna con la
         * llave vieja: ilegible después del reinicio, y sin forma de recuperarla.
         */
        foreach ($encontradas as $columna) {
            $this->assertContains(
                $columna,
                $declaradas,
                sprintf('«%s» está cifrada pero el comando de rotación no la conoce.', $columna),
            );
        }
    }

    public function test_el_simulacro_no_escribe_nada(): void
    {
        $escenario = $this->conUnaRespuestaAbierta('Texto que no se debe perder.');

        $antes = (string) DB::table('respuestas')->value('valor_texto');

        $this->artisan('mentia:rotar-llave', [
            '--nueva' => $this->llaveNueva(),
            '--simular' => true,
        ])->assertSuccessful();

        $this->assertSame($antes, (string) DB::table('respuestas')->value('valor_texto'));
        $this->assertSame('Texto que no se debe perder.', Respuesta::query()->value('valor_texto'));
    }

    public function test_rotar_deja_los_datos_legibles_con_la_llave_nueva(): void
    {
        $this->conUnaRespuestaAbierta('A veces me cuesta levantarme.');

        $nueva = $this->llaveNueva();

        $this->artisan('mentia:rotar-llave', ['--nueva' => $nueva])->assertSuccessful();

        $crudo = (string) DB::table('respuestas')->value('valor_texto');

        // Con la llave VIEJA ya no se puede: es lo que hace real la rotación.
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);

        $this->cifradorCon((string) config('app.key'))->decryptString($crudo);
    }

    public function test_lo_rotado_se_lee_con_la_llave_nueva(): void
    {
        $this->conUnaRespuestaAbierta('Duermo mal desde hace meses.');

        $nueva = $this->llaveNueva();

        $this->artisan('mentia:rotar-llave', ['--nueva' => $nueva])->assertSuccessful();

        $crudo = (string) DB::table('respuestas')->value('valor_texto');

        $this->assertSame(
            'Duermo mal desde hace meses.',
            $this->cifradorCon($nueva)->decryptString($crudo),
        );
    }

    public function test_sin_llave_nueva_no_hace_nada(): void
    {
        $this->artisan('mentia:rotar-llave')
            ->expectsOutputToContain('Falta --nueva')
            ->assertFailed();
    }

    public function test_una_llave_invalida_se_rechaza(): void
    {
        $this->artisan('mentia:rotar-llave', ['--nueva' => 'base64:demasiado-corta'])
            ->expectsOutputToContain('no es válida')
            ->assertFailed();
    }

    // ── Andamio ───────────────────────────────────────────────────────────

    private function conUnaRespuestaAbierta(string $texto): EscenarioCalificacion
    {
        $escenario = new EscenarioCalificacion(EscenarioTenant::nuevo()->activar());
        $reactivo = $escenario->reactivoDeSuma('DEP');
        $escenario->contestar([]);

        Respuesta::query()->create([
            'aplicacion_id' => $escenario->aplicacion->id,
            'reactivo_id' => $reactivo->id,
            'valor_texto' => $texto,
            'uuid_cliente' => (string) Str::uuid(),
            'respondida_en' => now(),
        ]);

        return $escenario;
    }

    private function llaveNueva(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    private function cifradorCon(string $llave): Encrypter
    {
        $cruda = str_starts_with($llave, 'base64:')
            ? (string) base64_decode(substr($llave, 7), true)
            : $llave;

        return new Encrypter($cruda, (string) config('app.cipher'));
    }

    /**
     * Los modelos del dominio, para poder mirar sus casts.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function modelosDelDominio(): array
    {
        $clases = [];

        $archivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Domain'))
        );

        foreach ($archivos as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $relativa = str_replace(
                [app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
                ['', '', '\\'],
                $archivo->getPathname(),
            );

            $clase = 'App\\'.$relativa;

            if (! class_exists($clase)) {
                continue;
            }

            $reflexion = new ReflectionClass($clase);

            if ($reflexion->isAbstract()
                || ! $reflexion->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $clases[] = $clase;
        }

        // `users` no vive en Domain; se agrega a mano.
        $clases[] = \App\Models\User::class;

        return $clases;
    }
}
