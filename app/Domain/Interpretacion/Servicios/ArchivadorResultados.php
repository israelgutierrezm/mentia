<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Modelos\EscalaArchivada;
use App\Domain\Interpretacion\Modelos\InterpretacionArchivada;
use App\Domain\Interpretacion\Modelos\ResultadoArchivado;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guarda el resultado actual antes de que una recalificación lo pise.
 *
 * Recalificar no es "volver a calcular": es sustituir lo que ya se le entregó a
 * alguien. Ese resultado anterior pudo haber sustentado una contratación o una
 * canalización, y una impugnación de hace seis meses tiene que poder
 * reconstruirse aunque el baremo con el que se calculó ya no exista.
 */
class ArchivadorResultados
{
    /**
     * Devuelve null si no había nada que archivar.
     */
    public function archivar(Aplicacion $aplicacion, string $motivo): ?ResultadoArchivado
    {
        $escalas = ResultadoEscala::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get();

        if ($escalas->isEmpty()) {
            // Nunca se calificó: no hay foto que tomar.
            return null;
        }

        $interpretaciones = ResultadoInterpretacion::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->orderBy('orden')
            ->get();

        $claves = Escala::query()
            ->whereIn('id', $escalas->pluck('escala_id')->all())
            ->pluck('clave', 'id');

        return DB::transaction(function () use (
            $aplicacion, $motivo, $escalas, $interpretaciones, $claves
        ): ResultadoArchivado {
            $archivo = ResultadoArchivado::query()->create([
                'aplicacion_id' => $aplicacion->id,
                'motivo' => $motivo,
                'validez' => $aplicacion->validez,
                'motivo_invalidez' => $aplicacion->motivo_invalidez,
                'version_archivo' => 1 + (int) ResultadoArchivado::query()
                    ->where('aplicacion_id', $aplicacion->id)
                    ->max('version_archivo'),
                'archivado_en' => Carbon::now(),
            ]);

            foreach ($escalas as $resultado) {
                EscalaArchivada::query()->create([
                    'resultado_archivado_id' => $archivo->id,
                    'escala_id' => $resultado->escala_id,
                    'escala_clave' => $claves[$resultado->escala_id] ?? '',
                    'puntaje_bruto' => $resultado->puntaje_bruto,
                    'baremo_id' => $resultado->baremo_id,
                    'valor_normalizado' => $resultado->valor_normalizado,
                    'tipo_norma' => $resultado->tipo_norma,
                    'etiqueta_norma' => $resultado->etiqueta_norma,
                    'sin_norma' => $resultado->sin_norma,
                ]);
            }

            foreach ($interpretaciones as $interpretacion) {
                InterpretacionArchivada::query()->create([
                    'resultado_archivado_id' => $archivo->id,
                    'audiencia' => $interpretacion->audiencia,
                    'texto_resuelto' => $interpretacion->texto_resuelto,
                    'bandera' => $interpretacion->bandera,
                    'orden' => $interpretacion->orden,
                ]);
            }

            return $archivo;
        });
    }
}
