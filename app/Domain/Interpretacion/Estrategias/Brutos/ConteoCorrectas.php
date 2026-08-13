<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `conteo_correctas` (Doc 05 §2): pruebas de ejecución máxima.
 *
 * Cuenta aciertos. La opción correcta es la que trae `es_correcta` en el
 * catálogo, no un peso: en una prueba de razonamiento no hay grados, se acertó
 * o no.
 *
 * Parámetro `correccion_adivinanza`: con él, aciertos − errores/(k−1), donde k
 * es el número de opciones. Corrige el puntaje que se gana al azar en opción
 * múltiple; se aplica sólo si el manual del instrumento lo indica, porque
 * castigar el intento cambia lo que la prueba mide.
 */
class ConteoCorrectas extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'conteo_correctas';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);
        $corrige = ($parametros['correccion_adivinanza'] ?? '0') === '1';

        /** @var array<int, array{aciertos: int, errores: int, opciones: int}> $conteo */
        $conteo = [];

        foreach ($contexto->reactivos as $reactivo) {
            $respuesta = $contexto->respuestasDe($reactivo->id)->first();

            if ($respuesta === null || $respuesta->opcion_id === null) {
                continue;
            }

            $opciones = OpcionReactivo::query()->where('reactivo_id', $reactivo->id)->get();
            $marcada = $opciones->firstWhere('id', $respuesta->opcion_id);

            if ($marcada === null) {
                continue;
            }

            /*
             * Las escalas DISTINTAS del reactivo, no sus claves. Un reactivo de
             * opción múltiple tiene una clave por opción y todas apuntan a la
             * misma escala; contar por clave sumaría el acierto tantas veces
             * como opciones tenga el reactivo, y el puntaje saldría multiplicado
             * sin que nada se vea roto.
             */
            $escalas = [];

            foreach ($claves[$reactivo->id] ?? [] as $clave) {
                $escalas[$clave->escala_id] = true;
            }

            foreach (array_keys($escalas) as $escalaId) {
                $conteo[$escalaId] ??= ['aciertos' => 0, 'errores' => 0, 'opciones' => $opciones->count()];

                if ($marcada->es_correcta === true) {
                    $conteo[$escalaId]['aciertos']++;
                } else {
                    $conteo[$escalaId]['errores']++;
                }
            }
        }

        foreach ($conteo as $escalaId => $datos) {
            $puntaje = (float) $datos['aciertos'];

            if ($corrige && $datos['opciones'] > 1) {
                $puntaje -= $datos['errores'] / ($datos['opciones'] - 1);
            }

            // Un puntaje negativo por corrección no existe: significa que se
            // falló más de lo que el azar explica, y eso es un cero, no una
            // deuda.
            $this->acumular($contexto, $escalaId, max(0.0, round($puntaje, 3)));
        }

        $this->completarEnCero($contexto);
    }
}
