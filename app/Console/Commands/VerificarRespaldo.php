<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba que un respaldo restaurado SIRVE (docs/incidentes.md §5).
 *
 * Un respaldo que nunca se restauró es una suposición, no un respaldo. Y que el
 * volcado cargue no prueba nada: lo que hay que verificar es que **los datos
 * cifrados se lean**, porque un respaldo cuyo contenido se cifró con una
 * `APP_KEY` que ya no existe está completo, íntegro y no sirve para nada.
 *
 * Se corre SIEMPRE contra una base desechable, nunca contra producción:
 *
 *   mysql -e "CREATE DATABASE mentia_restauracion"
 *   gunzip < respaldo.sql.gz | mysql mentia_restauracion
 *   php artisan mentia:verificar-respaldo --base=mentia_restauracion
 */
class VerificarRespaldo extends Command
{
    protected $signature = 'mentia:verificar-respaldo
        {--base= : La base restaurada (por omisión, la configurada)}
        {--muestra=20 : Cuántas filas revisar por columna}';

    protected $description = 'Comprueba que un respaldo restaurado se puede descifrar y leer.';

    public function handle(): int
    {
        $base = $this->option('base');

        if (is_string($base) && $base !== '') {
            /*
             * Se apunta la conexión a la base restaurada SIN tocar el `.env`:
             * un comando de verificación que exija cambiar la configuración del
             * entorno acaba corriéndose contra producción por accidente.
             */
            Config::set('database.connections.mysql.database', $base);
            DB::purge('mysql');

            $this->info('Verificando la base «'.$base.'».');
        } else {
            $this->warn('Sin --base: se verifica la base CONFIGURADA. Asegúrate de que no sea producción.');
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $error) {
            $this->error('No se pudo conectar: '.$error->getMessage());

            return self::FAILURE;
        }

        $muestra = max(1, (int) $this->option('muestra'));
        $revisadas = 0;
        $ilegibles = 0;
        $vacias = [];

        foreach (RotarLlaveDeCifrado::COLUMNAS_CIFRADAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                [$leidas, $rotas, $huboDatos] = $this->revisar($tabla, $columna, $muestra);

                $revisadas += $leidas;
                $ilegibles += $rotas;

                if (! $huboDatos) {
                    $vacias[] = $tabla.'.'.$columna;

                    continue;
                }

                $this->line(sprintf(
                    '  %-45s %3d leídas%s',
                    $tabla.'.'.$columna,
                    $leidas,
                    $rotas > 0 ? ', '.$rotas.' ILEGIBLES' : '',
                ));
            }
        }

        foreach ($vacias as $columna) {
            $this->line(sprintf('  %-45s sin datos', $columna));
        }

        if ($ilegibles > 0) {
            /*
             * Este es el caso que el comando existe para encontrar: el respaldo
             * está cifrado con una llave que este entorno no tiene. Hay que
             * localizar la llave de esa fecha ANTES de seguir; restaurar
             * encima produciría un sistema que arranca y con expedientes
             * vacíos.
             */
            $this->error(sprintf(
                '%d valores no se pudieron descifrar. El respaldo está cifrado con otra APP_KEY.',
                $ilegibles,
            ));

            return self::FAILURE;
        }

        if ($revisadas === 0) {
            $this->warn('No había ningún valor cifrado que revisar. La verificación no prueba nada.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Respaldo verificado: %d valores cifrados se leyeron bien.', $revisadas));
        $this->line('Anota la fecha y el tiempo que tomó (docs/incidentes.md §5).');

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function revisar(string $tabla, string $columna, int $muestra): array
    {
        try {
            $filas = DB::table($tabla)
                ->whereNotNull($columna)
                ->where($columna, '!=', '')
                ->limit($muestra)
                ->pluck($columna);
        } catch (Throwable $error) {
            $this->error(sprintf('  %-45s no se pudo leer: %s', $tabla.'.'.$columna, $error->getMessage()));

            return [0, 1, true];
        }

        if ($filas->isEmpty()) {
            return [0, 0, false];
        }

        $leidas = 0;
        $rotas = 0;

        foreach ($filas as $valor) {
            try {
                Crypt::decryptString((string) $valor);
                $leidas++;
            } catch (DecryptException) {
                $rotas++;
            }
        }

        return [$leidas, $rotas, true];
    }
}
