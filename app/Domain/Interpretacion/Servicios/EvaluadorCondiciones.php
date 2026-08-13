<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use Illuminate\Support\Collection;

/**
 * Compara un resultado contra la condición de una regla.
 *
 * Una condición declara SIEMPRE en qué tipo de puntaje está escrita: "percentil
 * mayor que 85" no es lo mismo que "bruto mayor que 85". Si el resultado no
 * está en ese tipo, la condición NO se cumple — y no se cumple a propósito, en
 * vez de comparar contra el número que haya. Una regla escrita en percentiles
 * evaluada contra un bruto dispararía interpretaciones al azar.
 */
class EvaluadorCondiciones
{
    /**
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    public function cumple(
        Collection $resultados,
        int $escalaId,
        string $tipoPuntaje,
        ?string $operador,
        ?float $valorMin,
        ?float $valorMax,
    ): bool {
        $resultado = $resultados->firstWhere('escala_id', $escalaId);

        if (! $resultado instanceof ResultadoEscala) {
            return false;
        }

        $valor = $resultado->valorEnTipo($tipoPuntaje);

        if ($valor === null) {
            return false;
        }

        return match ($operador) {
            '>' => $valorMin !== null && $valor > $valorMin,
            '>=' => $valorMin !== null && $valor >= $valorMin,
            '<' => $valorMax !== null && $valor < $valorMax,
            '<=' => $valorMax !== null && $valor <= $valorMax,
            '=', '==' => $valorMin !== null && $valor === $valorMin,
            '!=' => $valorMin !== null && $valor !== $valorMin,

            /*
             * `entre` es INCLUSIVO en los dos extremos. Es la forma en que
             * están escritas las tablas de los manuales —«10 a 14: moderada»—
             * y dejar fuera el 14 movería silenciosamente la categoría de
             * quien cae justo en el corte.
             */
            'entre', null => $this->entre($valor, $valorMin, $valorMax),

            default => false,
        };
    }

    private function entre(float $valor, ?float $minimo, ?float $maximo): bool
    {
        if ($minimo !== null && $valor < $minimo) {
            return false;
        }

        return ! ($maximo !== null && $valor > $maximo);
    }
}
