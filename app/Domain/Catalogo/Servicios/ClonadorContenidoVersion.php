<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use App\Domain\Catalogo\Modelos\Baremo;
use App\Domain\Catalogo\Modelos\BaremoFila;
use App\Domain\Catalogo\Modelos\Bloque;
use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\FormulaDerivada;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\ReglaInterpretacion;
use App\Domain\Catalogo\Modelos\ReglaInterpretacionCondicion;
use App\Domain\Catalogo\Modelos\VersionInstrumento;

/**
 * Copia el contenido de una versión a otra.
 *
 * Existe para que "corregir una versión publicada" tenga una respuesta que no
 * sea editarla: se clona a un borrador y se corrige la copia. Sin esto, un
 * error de dedo en un reactivo obligaría a recapturar el instrumento entero, y
 * la presión por editar la publicada sería irresistible.
 *
 * El orden importa: las claves apuntan a reactivos y opciones, las fórmulas y
 * las reglas a escalas. Se clona por capas guardando la equivalencia de ids
 * viejos a nuevos.
 */
class ClonadorContenidoVersion
{
    public function clonar(VersionInstrumento $origen, VersionInstrumento $destino): void
    {
        $escalas = $this->clonarEscalas($origen, $destino);
        $bloques = $this->clonarBloques($origen, $destino);
        [$reactivos, $opciones] = $this->clonarReactivos($origen, $destino, $bloques);

        $this->clonarClaves($origen, $destino, $reactivos, $opciones, $escalas);
        $this->clonarFormulas($origen, $destino, $escalas);
        $this->clonarBaremos($origen, $destino, $escalas);
        $this->clonarInterpretaciones($origen, $destino, $escalas);
    }

    /**
     * @return array<int, int> id viejo => id nuevo
     */
    private function clonarEscalas(VersionInstrumento $origen, VersionInstrumento $destino): array
    {
        $mapa = [];

        $escalas = Escala::query()
            ->where('version_instrumento_id', $origen->id)
            ->orderBy('id')
            ->get();

        // Dos pasadas: la segunda re-ata `escala_padre_id`, que puede apuntar
        // a una escala clonada después.
        foreach ($escalas as $escala) {
            $nueva = Escala::query()->create([
                'version_instrumento_id' => $destino->id,
                'clave' => $escala->clave,
                'nombre' => $escala->nombre,
                'escala_padre_id' => null,
                'es_validez' => $escala->es_validez,
                'orden' => $escala->orden,
            ]);

            $mapa[$escala->id] = $nueva->id;
        }

        foreach ($escalas as $escala) {
            if ($escala->escala_padre_id === null) {
                continue;
            }

            Escala::query()->where('id', $mapa[$escala->id])->update([
                'escala_padre_id' => $mapa[$escala->escala_padre_id] ?? null,
            ]);
        }

        return $mapa;
    }

    /**
     * @return array<int, int>
     */
    private function clonarBloques(VersionInstrumento $origen, VersionInstrumento $destino): array
    {
        $mapa = [];

        foreach (Bloque::query()->where('version_instrumento_id', $origen->id)->get() as $bloque) {
            $nuevo = Bloque::query()->create([
                'version_instrumento_id' => $destino->id,
                'clave' => $bloque->clave,
                'titulo' => $bloque->titulo,
                'instrucciones' => $bloque->instrucciones,
                'orden' => $bloque->orden,
                'tiempo_limite_seg' => $bloque->tiempo_limite_seg,
                'orden_reactivos' => $bloque->orden_reactivos,
                'es_practica' => $bloque->es_practica,
            ]);

            $mapa[$bloque->id] = $nuevo->id;
        }

        return $mapa;
    }

    /**
     * @param  array<int, int>  $bloques
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function clonarReactivos(
        VersionInstrumento $origen,
        VersionInstrumento $destino,
        array $bloques,
    ): array {
        $mapaReactivos = [];
        $mapaOpciones = [];

        $reactivos = Reactivo::query()
            ->where('version_instrumento_id', $origen->id)
            ->with('opciones')
            ->get();

        foreach ($reactivos as $reactivo) {
            $nuevo = Reactivo::query()->create([
                'version_instrumento_id' => $destino->id,
                'bloque_id' => $bloques[$reactivo->bloque_id],
                'tipo_reactivo_id' => $reactivo->tipo_reactivo_id,
                'codigo' => $reactivo->codigo,
                'enunciado' => $reactivo->enunciado,
                'media_id' => $reactivo->media_id,

                // El ámbito de contenido se conserva: lo que era privado de un
                // tenant sigue siéndolo en la versión nueva.
                'organizacion_id_contenido' => $reactivo->organizacion_id_contenido,

                'es_inverso' => $reactivo->es_inverso,
                'es_centinela' => $reactivo->es_centinela,
                'obligatorio' => $reactivo->obligatorio,
                'orden' => $reactivo->orden,
                'tiempo_limite_seg' => $reactivo->tiempo_limite_seg,
            ]);

            $mapaReactivos[$reactivo->id] = $nuevo->id;

            foreach ($reactivo->opciones as $opcion) {
                $nuevaOpcion = OpcionReactivo::query()->create([
                    'reactivo_id' => $nuevo->id,
                    'codigo' => $opcion->codigo,
                    'texto' => $opcion->texto,
                    'media_id' => $opcion->media_id,
                    'organizacion_id_contenido' => $opcion->organizacion_id_contenido,
                    'es_correcta' => $opcion->es_correcta,
                    'orden' => $opcion->orden,
                ]);

                $mapaOpciones[$opcion->id] = $nuevaOpcion->id;
            }
        }

        return [$mapaReactivos, $mapaOpciones];
    }

    /**
     * @param  array<int, int>  $reactivos
     * @param  array<int, int>  $opciones
     * @param  array<int, int>  $escalas
     */
    private function clonarClaves(
        VersionInstrumento $origen,
        VersionInstrumento $destino,
        array $reactivos,
        array $opciones,
        array $escalas,
    ): void {
        $claves = ClaveCalificacion::query()
            ->where('version_instrumento_id', $origen->id)
            ->get();

        foreach ($claves as $clave) {
            ClaveCalificacion::query()->create([
                'version_instrumento_id' => $destino->id,
                'reactivo_id' => $reactivos[$clave->reactivo_id],
                'opcion_id' => $clave->opcion_id === null ? null : ($opciones[$clave->opcion_id] ?? null),
                'escala_id' => $escalas[$clave->escala_id],
                'peso' => $clave->peso,
                'rol' => $clave->rol,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $escalas
     */
    private function clonarFormulas(
        VersionInstrumento $origen,
        VersionInstrumento $destino,
        array $escalas,
    ): void {
        $formulas = FormulaDerivada::query()
            ->where('version_instrumento_id', $origen->id)
            ->get();

        foreach ($formulas as $formula) {
            FormulaDerivada::query()->create([
                'version_instrumento_id' => $destino->id,
                'escala_destino_id' => $escalas[$formula->escala_destino_id],

                // La expresión se copia TAL CUAL: va sobre claves de escala,
                // que no cambian al clonar. Es exactamente por lo que no va
                // sobre ids.
                'expresion' => $formula->expresion,

                'orden_evaluacion' => $formula->orden_evaluacion,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $escalas
     */
    private function clonarBaremos(
        VersionInstrumento $origen,
        VersionInstrumento $destino,
        array $escalas,
    ): void {
        $baremos = Baremo::query()
            ->where('version_instrumento_id', $origen->id)
            ->with('filas')
            ->get();

        foreach ($baremos as $baremo) {
            $nuevo = Baremo::query()->create([
                'version_instrumento_id' => $destino->id,
                'escala_id' => $escalas[$baremo->escala_id],
                'poblacion_norma_id' => $baremo->poblacion_norma_id,
                'organizacion_id' => $baremo->organizacion_id,
                'tipo_norma' => $baremo->tipo_norma,
                'vigente' => $baremo->vigente,
                'fuente' => $baremo->fuente,
            ]);

            foreach ($baremo->filas as $fila) {
                BaremoFila::query()->create([
                    'baremo_id' => $nuevo->id,
                    'bruto_min' => $fila->bruto_min,
                    'bruto_max' => $fila->bruto_max,
                    'edad_min_meses' => $fila->edad_min_meses,
                    'edad_max_meses' => $fila->edad_max_meses,
                    'sexo' => $fila->sexo,
                    'escolaridad_id' => $fila->escolaridad_id,
                    'valor_normalizado' => $fila->valor_normalizado,
                    'etiqueta' => $fila->etiqueta,
                ]);
            }
        }
    }

    /**
     * @param  array<int, int>  $escalas
     */
    private function clonarInterpretaciones(
        VersionInstrumento $origen,
        VersionInstrumento $destino,
        array $escalas,
    ): void {
        $reglas = ReglaInterpretacion::query()
            ->where('version_instrumento_id', $origen->id)
            ->with('condiciones')
            ->get();

        foreach ($reglas as $regla) {
            $nueva = ReglaInterpretacion::query()->create([
                'version_instrumento_id' => $destino->id,
                'escala_id' => $regla->escala_id === null ? null : $escalas[$regla->escala_id],
                'tipo_regla' => $regla->tipo_regla,
                'tipo_puntaje' => $regla->tipo_puntaje,
                'operador' => $regla->operador,
                'valor_min' => $regla->valor_min,
                'valor_max' => $regla->valor_max,
                'audiencia' => $regla->audiencia,
                'texto_interpretacion' => $regla->texto_interpretacion,
                'recomendaciones' => $regla->recomendaciones,
                'bandera' => $regla->bandera,
                'prioridad' => $regla->prioridad,
                'vigente' => $regla->vigente,
            ]);

            foreach ($regla->condiciones as $condicion) {
                ReglaInterpretacionCondicion::query()->create([
                    'regla_id' => $nueva->id,
                    'escala_id' => $escalas[$condicion->escala_id],
                    'tipo_puntaje' => $condicion->tipo_puntaje,
                    'operador' => $condicion->operador,
                    'valor_min' => $condicion->valor_min,
                    'valor_max' => $condicion->valor_max,
                    'conector' => $condicion->conector,
                ]);
            }
        }
    }
}
