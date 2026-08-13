<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Catalogo\Servicios\ImportadorInstrumento;
use App\Domain\Catalogo\Servicios\PublicadorVersion;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Siembra los instrumentos oficiales desde `database/seeds/instrumentos`
 * (Doc 08, Fase 4).
 *
 * IDEMPOTENTE: correrlo dos veces no duplica nada. Un instrumento cuya versión
 * ya está publicada se salta —publicada quiere decir congelada (principio P4)—,
 * y volver a sembrarlo encima sería justo lo que la inmutabilidad impide.
 *
 * Entra por el MISMO servicio que el importador de Excel, así que comparte
 * validación, reporte fila a fila y rollback total.
 */
class SembrarInstrumentos extends Command
{
    protected $signature = 'mentia:seed-instrumentos
        {clave? : La clave de un instrumento; sin ella, todos}
        {--publicar : Publica la versión al terminar}
        {--rehacer : Vuelve a cargar aunque la versión ya esté publicada}
        {--directorio= : Otro directorio de archivos de datos}';

    protected $description = 'Carga los instrumentos oficiales desde sus archivos de datos.';

    public function handle(
        ImportadorInstrumento $importador,
        PublicadorVersion $publicador,
        ContextoOrganizacion $contexto,
    ): int {
        $archivos = $this->archivos();

        if ($archivos === []) {
            /*
             * No es un error: es el estado normal hasta que llegue el contenido
             * revisado. Se dice claramente para que nadie concluya que el
             * comando está roto.
             */
            $this->warn('No hay archivos de datos en database/seeds/instrumentos.');
            $this->line('La maquinaria está lista; falta el contenido. Ver el LEEME de ese directorio.');

            return self::SUCCESS;
        }

        $fallos = 0;

        foreach ($archivos as $clave => $ruta) {
            /*
             * `sinRestriccion`: un comando no tiene organización activa y el
             * global scope falla cerrado. El contenido oficial es GLOBAL
             * —`organizacion_id_contenido` en null—, así que no hay tenant que
             * fijar, sólo restricción que levantar.
             */
            $resultado = $contexto->sinRestriccion(
                fn (): bool => $this->sembrarUno($clave, $ruta, $importador, $publicador)
            );

            $fallos += $resultado ? 0 : 1;
        }

        if ($fallos > 0) {
            $this->error(sprintf('%d instrumentos no se sembraron.', $fallos));

            return self::FAILURE;
        }

        $this->info('Listo.');

        return self::SUCCESS;
    }

    private function sembrarUno(
        string $clave,
        string $ruta,
        ImportadorInstrumento $importador,
        PublicadorVersion $publicador,
    ): bool {
        $existente = Instrumento::query()->where('clave', $clave)->first();

        if ($existente !== null) {
            $publicada = VersionInstrumento::query()
                ->where('instrumento_id', $existente->id)
                ->where('estado', VersionInstrumento::PUBLICADA)
                ->exists();

            if ($publicada && ! $this->option('rehacer')) {
                $this->line(sprintf('  %-20s ya publicado, se salta.', $clave));

                return true;
            }
        }

        $hojas = $this->leerArchivo($ruta);

        if ($hojas === null) {
            $this->error(sprintf('  %-20s el archivo no devuelve un arreglo de hojas.', $clave));

            return false;
        }

        $reporte = $importador->importarHojas($hojas);

        if ($reporte->tieneErrores()) {
            $this->error(sprintf('  %-20s %d errores:', $clave, count($reporte->errores())));

            // Hoja, fila y columna: sin eso, "el archivo tiene errores" manda a
            // quien lo escribió a revisar mil renglones a ojo.
            foreach (array_slice($reporte->errores(), 0, 10) as $error) {
                $this->line(sprintf(
                    '      [%s] fila %d%s: %s',
                    $error['hoja'],
                    $error['fila'],
                    $error['columna'] === null ? '' : ', columna '.$error['columna'],
                    $error['mensaje'],
                ));
            }

            return false;
        }

        $this->line(sprintf('  %-20s cargado.', $clave));

        if ($this->option('publicar')) {
            $version = VersionInstrumento::query()
                ->whereHas('instrumento', fn ($consulta) => $consulta->where('clave', $clave))
                ->where('estado', VersionInstrumento::BORRADOR)
                ->latest('id')
                ->first();

            if ($version !== null) {
                try {
                    $publicador->publicar($version);
                    $this->line(sprintf('  %-20s publicado.', $clave));
                } catch (Throwable $error) {
                    /*
                     * Publicar valida que haya reactivos, que ninguna escala
                     * quede sin claves y que las fórmulas citen escalas
                     * existentes. Si falla, el instrumento se queda en borrador
                     * —cargado pero no asignable—, que es exactamente donde
                     * debe quedarse hasta que alguien lo arregle.
                     */
                    $this->error(sprintf('  %-20s no se pudo publicar: %s', $clave, $error->getMessage()));

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Lee el archivo de datos y lo normaliza a lo que el importador espera.
     *
     * Dos normalizaciones, y las dos hacen falta:
     *
     * - **Todo a cadena.** Es lo que produce una hoja de cálculo y lo que el
     *   importador valida. Un `0` numérico y un `'0'` de texto se comportan
     *   distinto al comprobar campos vacíos.
     * - **`__fila`**, el número de renglón con el que el importador reporta los
     *   errores. Sin él, «la hoja reactivos tiene un error» manda a quien
     *   escribió el archivo a revisar mil renglones a ojo.
     *
     * @return array<string, list<array<string, string>>>|null
     */
    private function leerArchivo(string $ruta): ?array
    {
        $contenido = require $ruta;

        if (! is_array($contenido)) {
            return null;
        }

        $hojas = [];

        foreach ($contenido as $hoja => $filas) {
            if (! is_array($filas)) {
                continue;
            }

            $normalizadas = [];

            foreach (array_values($filas) as $indice => $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $registro = [];

                foreach ($fila as $columna => $valor) {
                    $registro[(string) $columna] = is_scalar($valor) ? (string) $valor : '';
                }

                // +1 porque quien lee el archivo cuenta desde el primer
                // elemento del arreglo, no desde cero.
                $registro['__fila'] = (string) ($indice + 1);

                $normalizadas[] = $registro;
            }

            $hojas[(string) $hoja] = $normalizadas;
        }

        /** @var array<string, list<array<string, string>>> $hojas */
        return $hojas;
    }

    /**
     * Los archivos de datos, por clave.
     *
     * @return array<string, string>
     */
    private function archivos(): array
    {
        /*
         * `--directorio` existe para dos cosas: probar el cargador contra un
         * juego de datos sintético —sin meterlo en el directorio del contenido
         * oficial— y cargar el seed propio de un tenant desde otra ruta.
         */
        $directorio = $this->option('directorio') ?? base_path('database/seeds/instrumentos');

        if (! is_string($directorio) || ! is_dir($directorio)) {
            return [];
        }

        $pedida = $this->argument('clave');
        $archivos = [];

        foreach ((array) glob($directorio.'/*.php') as $ruta) {
            if (! is_string($ruta)) {
                continue;
            }

            $clave = basename($ruta, '.php');

            // Los casos dorados viven junto al instrumento y no son
            // instrumentos: los corre la suite, no este comando.
            if (str_ends_with($clave, '-casos')) {
                continue;
            }

            if ($pedida !== null && $clave !== $pedida) {
                continue;
            }

            $archivos[$clave] = $ruta;
        }

        ksort($archivos);

        return $archivos;
    }
}
