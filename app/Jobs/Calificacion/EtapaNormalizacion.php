<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Servicios\ResolutorBaremo;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 4 — normalización (Doc 05 §2).
 *
 * Es la etapa que hace comparable un resultado de los 6 años con uno de los 22,
 * que es la idea rectora del proyecto. Un bruto de 18 no significa nada fuera
 * de su propia aplicación; un percentil 70 sí.
 *
 * Dos reglas que no se negocian:
 *
 * 1. **Lo que ya clasificó un algoritmo especial no se toca.** Los cortes de la
 *    NOM-035 los publicó el DOF; volver a normalizarlos contra una tabla de
 *    percentiles produciría un semáforo que se ve como el oficial y no lo es.
 * 2. **Sin baremo aplicable se marca `sin_norma`, no se inventa un número.**
 */
class EtapaNormalizacion extends EtapaDelPipeline
{
    protected function etapa(): string
    {
        return 'normalizacion';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        $resolutor = app(ResolutorBaremo::class);
        $aplicacion = $contexto->aplicacion;

        $resultados = ResultadoEscala::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get();

        DB::transaction(function () use ($resultados, $resolutor, $aplicacion, $contexto): void {
            foreach ($resultados as $resultado) {
                if ($resultado->tipo_norma !== null) {
                    // Ya lo clasificó la etapa 3.
                    continue;
                }

                $baremo = $resolutor->resolver($aplicacion, $resultado->escala_id);
                $fila = $baremo === null
                    ? null
                    : $resolutor->filaPara($baremo, $aplicacion, $resultado->puntaje_bruto);

                if ($baremo === null || $fila === null) {
                    $resultado->update(['sin_norma' => true]);

                    continue;
                }

                $resultado->update([
                    'baremo_id' => $baremo->id,
                    'valor_normalizado' => $fila->valor_normalizado,
                    'tipo_norma' => $baremo->tipo_norma,
                    'etiqueta_norma' => $fila->etiqueta,
                    'sin_norma' => false,
                ]);
            }

            $this->alimentarSerieLongitudinal($contexto, $resultados->fresh());
        });
    }

    /**
     * Escribe la serie de `resultados_normalizados`.
     *
     * Es lo que convierte una aplicación suelta en un punto de la línea de
     * tiempo de la persona. Sólo entran resultados CON norma: una serie que
     * mezclara brutos de instrumentos distintos dibujaría una gráfica que sube
     * y baja por cambiar de prueba, no por cambiar la persona.
     *
     * Las aplicaciones ANÓNIMAS no alimentan nada: no hay persona de la cual
     * colgar la serie, y reconstruirla sería deshacer el anonimato que la
     * NOM-035 necesita para que la gente conteste con la verdad.
     *
     * @param  \Illuminate\Support\Collection<int, ResultadoEscala>  $resultados
     */
    private function alimentarSerieLongitudinal(ContextoCalificacion $contexto, $resultados): void
    {
        $aplicacion = $contexto->aplicacion;

        if ($aplicacion->persona_id === null) {
            return;
        }

        $dominioId = $aplicacion->version->instrumento->dominio_id;
        $fecha = $contexto->fechaDeAplicacion()->toDateString();

        // Recalificar reescribe la serie de ESTA aplicación, no agrega puntos:
        // dos puntos el mismo día por el mismo instrumento serían un cambio que
        // nunca ocurrió.
        ResultadoNormalizado::query()->where('aplicacion_id', $aplicacion->id)->delete();

        foreach ($resultados as $resultado) {
            if ($resultado->sin_norma || $resultado->tipo_norma === null) {
                continue;
            }

            $escala = $contexto->escalas->firstWhere('id', $resultado->escala_id);

            if ($escala === null) {
                continue;
            }

            /*
             * El semáforo entra a la serie con su valor bruto: la etiqueta es
             * lo que significa, y el bruto es lo único ordenable para dibujar
             * una evolución.
             */
            $valor = $resultado->valor_normalizado ?? $resultado->puntaje_bruto;

            ResultadoNormalizado::query()->create([
                'persona_id' => $aplicacion->persona_id,
                'dominio_id' => $dominioId,
                'constructo' => $escala->clave,
                'version_instrumento_id' => $aplicacion->version_instrumento_id,
                'aplicacion_id' => $aplicacion->id,
                'organizacion_id_contexto' => $aplicacion->organizacion_id,
                'fecha' => $fecha,
                'tipo_norma' => $resultado->tipo_norma,
                'valor' => $valor,
            ]);
        }
    }
}
