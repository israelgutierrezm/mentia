<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Servicios\ArchivadorResultados;
use App\Jobs\CalificarAplicacion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recalificación administrativa (Doc 08, Fase 7).
 *
 * Se corrigió un baremo, se arregló una clave, se publicó una versión nueva de
 * las interpretaciones: hay que volver a calificar aplicaciones que ya estaban
 * calificadas.
 *
 * ANTES DE RECALIFICAR SE ARCHIVA. El resultado anterior es lo que se le
 * entregó a alguien —posiblemente lo que sustentó una decisión de contratación
 * o una canalización— y no puede desaparecer porque el catálogo cambió. Sin el
 * archivo, una impugnación de hace seis meses no se puede reconstruir.
 */
class RecalificarAplicaciones extends Command
{
    /**
     * `--instrumento` y no `--version`: Artisan ya define `--version` para sí
     * mismo, y declararla aquí revienta el comando entero.
     */
    protected $signature = 'mentia:recalificar
        {--aplicacion= : UUID de una aplicación concreta}
        {--instrumento= : ID de versión de instrumento: recalifica todas las suyas}
        {--sin-archivar : Recalifica sin guardar el resultado anterior}
        {--ahora : Corre el pipeline síncrono en vez de encolarlo}';

    protected $description = 'Vuelve a calificar aplicaciones conservando el resultado anterior.';

    public function handle(ArchivadorResultados $archivador): int
    {
        $aplicaciones = $this->seleccionar();

        if ($aplicaciones === []) {
            $this->warn('No hay aplicaciones que recalificar con ese criterio.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Se van a recalificar %d aplicaciones.', count($aplicaciones)));

        foreach ($aplicaciones as $aplicacion) {
            if (! $this->option('sin-archivar')) {
                $archivador->archivar($aplicacion, motivo: 'Recalificación administrativa');
            }

            if ($this->option('ahora')) {
                foreach (CalificarAplicacion::etapas($aplicacion->id) as $etapa) {
                    /** @var object{handle: callable} $etapa */
                    $etapa->handle();
                }

                continue;
            }

            CalificarAplicacion::dispatch($aplicacion->id);
        }

        $this->info($this->option('ahora')
            ? 'Recalificación terminada.'
            : 'Recalificación encolada en la cola «calificacion».');

        return self::SUCCESS;
    }

    /**
     * @return list<Aplicacion>
     */
    private function seleccionar(): array
    {
        $uuid = $this->option('aplicacion');
        $version = $this->option('instrumento');

        if ($uuid === null && $version === null) {
            /*
             * Sin criterio NO se recalifica todo. Una recalificación masiva sin
             * querer sobre una base con cien mil aplicaciones es una tarde de
             * cola y un montón de expedientes tocados sin razón.
             */
            $this->error('Hay que decir qué recalificar: --aplicacion=UUID o --instrumento=ID.');

            return [];
        }

        /*
         * `withoutGlobalScopes`: un comando no tiene organización activa y el
         * scope de tenant falla cerrado, así que sin esto no encontraría nada
         * y lo diría con un "no hay aplicaciones" perfectamente engañoso.
         */
        return Aplicacion::query()
            ->withoutGlobalScopes()
            ->where('estado', 'completada')
            ->when($uuid !== null, fn (Builder $consulta) => $consulta->where('uuid', $uuid))
            ->when($version !== null, fn (Builder $consulta) => $consulta
                ->where('version_instrumento_id', $version))
            ->get()
            ->all();
    }
}
