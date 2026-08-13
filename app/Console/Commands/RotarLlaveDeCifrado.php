<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recifra todo con una llave NUEVA.
 *
 * `APP_KEY` descifra las notas profesionales, las respuestas abiertas, los
 * valores del expediente, las interpretaciones resueltas y los secretos de 2FA.
 * Cambiarla sin recifrar deja todo eso ilegible PARA SIEMPRE: no es un error
 * que se arregle volviendo atrás, porque los datos siguen cifrados con la llave
 * vieja que ya nadie tiene.
 *
 * ORDEN CORRECTO DE USO:
 *
 *   1. Respaldar la base. En serio.
 *   2. `php artisan key:generate --show` para obtener la nueva sin aplicarla.
 *   3. `php artisan mentia:rotar-llave --nueva=base64:… --simular`
 *   4. Si el simulacro pasa: el mismo comando sin `--simular`.
 *   5. Poner la nueva llave en `APP_KEY` y reiniciar.
 *
 * El paso 5 va DESPUÉS, y eso no es un descuido: mientras el comando corre, la
 * aplicación tiene que poder seguir descifrando con la llave vieja para leer lo
 * que todavía no se ha convertido.
 */
class RotarLlaveDeCifrado extends Command
{
    protected $signature = 'mentia:rotar-llave
        {--nueva= : La llave nueva, en formato base64:…}
        {--simular : Descifra y vuelve a cifrar sin escribir nada}';

    protected $description = 'Recifra las columnas cifradas con una llave nueva.';

    /**
     * Tabla => columnas cifradas.
     *
     * Esta lista ES la definición de qué está cifrado en el sistema. Agregar un
     * cast `encrypted` en un modelo y no agregarlo aquí produce una rotación que
     * parece exitosa y deja una columna con la llave vieja — ilegible después
     * del reinicio. Por eso hay una prueba que compara esta lista contra los
     * casts declarados en los modelos.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNAS_CIFRADAS = [
        'respuestas' => ['valor_texto'],
        'expediente_valores' => ['valor_texto'],
        'notas_profesionales' => ['contenido'],
        'resultados_interpretacion' => ['texto_resuelto'],
        'resultado_archivado_interpretacion' => ['texto_resuelto'],
        'users' => ['dos_factores_secreto', 'dos_factores_recuperacion'],
    ];

    public function handle(): int
    {
        $nueva = $this->option('nueva');

        if (! is_string($nueva) || $nueva === '') {
            $this->error('Falta --nueva=base64:… Genérala con `php artisan key:generate --show`.');

            return self::FAILURE;
        }

        $simula = (bool) $this->option('simular');

        $viejo = $this->cifradorActual();
        $nuevo = $this->cifradorCon($nueva);

        if ($nuevo === null) {
            $this->error('La llave nueva no es válida para el cifrador configurado.');

            return self::FAILURE;
        }

        $this->info($simula
            ? 'SIMULACRO: se descifra y se vuelve a cifrar sin escribir nada.'
            : 'Rotando. NO reinicies la aplicación hasta que termine.');

        $total = 0;
        $fallos = 0;

        foreach (self::COLUMNAS_CIFRADAS as $tabla => $columnas) {
            [$convertidas, $errores] = $this->rotarTabla(
                $tabla, $columnas, $viejo, $nuevo, $simula
            );

            $total += $convertidas;
            $fallos += $errores;

            $this->line(sprintf('  %-38s %5d valores%s', $tabla, $convertidas,
                $errores > 0 ? ' ('.$errores.' ilegibles)' : ''));
        }

        if ($fallos > 0) {
            /*
             * Un valor que no se pudo descifrar con la llave vieja significa
             * que ya venía roto o que se cifró con otra. Se avisa fuerte y NO
             * se sigue: rotar el resto dejaría la base a medias, con unas
             * columnas en la llave nueva y otras en una que nadie conoce.
             */
            $this->error(sprintf(
                '%d valores no se pudieron descifrar con la llave actual. No se rotó nada más.',
                $fallos,
            ));

            return self::FAILURE;
        }

        $this->info($simula
            ? sprintf('Simulacro correcto: %d valores se pueden rotar.', $total)
            : sprintf('Listos %d valores. Ahora sí, pon la llave nueva en APP_KEY y reinicia.', $total));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $columnas
     * @return array{0: int, 1: int}
     */
    private function rotarTabla(
        string $tabla,
        array $columnas,
        Encrypter $viejo,
        Encrypter $nuevo,
        bool $simula,
    ): array {
        $convertidas = 0;
        $errores = 0;

        /*
         * Por trozos y con `orderBy('id')`: una tabla de respuestas se proyecta
         * en decenas de millones de filas, y cargarlas todas para rotarlas
         * tumbaría el proceso mucho antes de terminar.
         */
        DB::table($tabla)->orderBy('id')->chunkById(500, function ($filas) use (
            $tabla, $columnas, $viejo, $nuevo, $simula, &$convertidas, &$errores
        ): void {
            foreach ($filas as $fila) {
                $cambios = [];

                foreach ($columnas as $columna) {
                    $valor = $fila->{$columna} ?? null;

                    if ($valor === null || $valor === '') {
                        continue;
                    }

                    try {
                        $claro = $viejo->decryptString((string) $valor);
                    } catch (Throwable) {
                        $errores++;

                        continue;
                    }

                    $cambios[$columna] = $nuevo->encryptString($claro);
                    $convertidas++;
                }

                if ($cambios !== [] && ! $simula) {
                    DB::table($tabla)->where('id', $fila->id)->update($cambios);
                }
            }
        });

        return [$convertidas, $errores];
    }

    private function cifradorActual(): Encrypter
    {
        return new Encrypter($this->llaveCruda((string) config('app.key')), $this->cifrado());
    }

    private function cifradorCon(string $llave): ?Encrypter
    {
        $cruda = $this->llaveCruda($llave);

        if (! Encrypter::supported($cruda, $this->cifrado())) {
            return null;
        }

        return new Encrypter($cruda, $this->cifrado());
    }

    private function llaveCruda(string $llave): string
    {
        return str_starts_with($llave, 'base64:')
            ? (string) base64_decode(substr($llave, 7), true)
            : $llave;
    }

    private function cifrado(): string
    {
        return (string) config('app.cipher', 'AES-256-CBC');
    }
}
