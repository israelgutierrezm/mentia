<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Interpretacion\Excepciones\FormulaNoEvaluable;

/**
 * Evalúa las fórmulas derivadas del catálogo (`T = M - L`, `(A + B) / 2`).
 *
 * SIN `eval()`. Una expresión que llegó de una hoja de Excel subida por un
 * tenant, ejecutándose como PHP, es ejecución remota de código con los pasos
 * intermedios ya hechos. Aquí se parsea a mano: números, claves de escala, los
 * cuatro operadores y paréntesis. Nada más existe en esta gramática, así que no
 * hay nada más que se pueda colar.
 *
 * La validación de que las claves citadas existan se hace al PUBLICAR
 * (`PublicadorVersion`). Esto sólo evalúa, y falla ruidoso si algo no cuadra.
 */
class EvaluadorFormulas
{
    /** @var list<array{tipo: string, valor: string}> */
    private array $simbolos = [];

    private int $posicion = 0;

    /**
     * @param  array<string, float>  $valores  Clave de escala → puntaje.
     *
     * @throws FormulaNoEvaluable
     */
    public function evaluar(string $expresion, array $valores): float
    {
        $this->simbolos = $this->tokenizar($expresion);
        $this->posicion = 0;

        $resultado = $this->suma($valores, $expresion);

        if ($this->posicion < count($this->simbolos)) {
            throw FormulaNoEvaluable::porSobrante($expresion, $this->simbolos[$this->posicion]['valor']);
        }

        return $resultado;
    }

    /**
     * @return list<array{tipo: string, valor: string}>
     *
     * @throws FormulaNoEvaluable
     */
    private function tokenizar(string $expresion): array
    {
        $simbolos = [];
        $largo = strlen($expresion);
        $i = 0;

        while ($i < $largo) {
            $caracter = $expresion[$i];

            if (ctype_space($caracter)) {
                $i++;

                continue;
            }

            if (str_contains('+-*/()', $caracter)) {
                $simbolos[] = ['tipo' => $caracter === '(' || $caracter === ')' ? 'parentesis' : 'operador', 'valor' => $caracter];
                $i++;

                continue;
            }

            if (ctype_digit($caracter) || $caracter === '.') {
                $numero = '';

                while ($i < $largo && (ctype_digit($expresion[$i]) || $expresion[$i] === '.')) {
                    $numero .= $expresion[$i];
                    $i++;
                }

                $simbolos[] = ['tipo' => 'numero', 'valor' => $numero];

                continue;
            }

            if (ctype_alpha($caracter) || $caracter === '_') {
                $nombre = '';

                while ($i < $largo && (ctype_alnum($expresion[$i]) || $expresion[$i] === '_')) {
                    $nombre .= $expresion[$i];
                    $i++;
                }

                $simbolos[] = ['tipo' => 'escala', 'valor' => $nombre];

                continue;
            }

            throw FormulaNoEvaluable::porCaracter($expresion, $caracter);
        }

        return $simbolos;
    }

    /**
     * @param  array<string, float>  $valores
     *
     * @throws FormulaNoEvaluable
     */
    private function suma(array $valores, string $expresion): float
    {
        $izquierda = $this->producto($valores, $expresion);

        while ($this->siguienteEs('+') || $this->siguienteEs('-')) {
            $operador = $this->simbolos[$this->posicion]['valor'];
            $this->posicion++;
            $derecha = $this->producto($valores, $expresion);

            $izquierda = $operador === '+' ? $izquierda + $derecha : $izquierda - $derecha;
        }

        return $izquierda;
    }

    /**
     * @param  array<string, float>  $valores
     *
     * @throws FormulaNoEvaluable
     */
    private function producto(array $valores, string $expresion): float
    {
        $izquierda = $this->unario($valores, $expresion);

        while ($this->siguienteEs('*') || $this->siguienteEs('/')) {
            $operador = $this->simbolos[$this->posicion]['valor'];
            $this->posicion++;
            $derecha = $this->unario($valores, $expresion);

            if ($operador === '/') {
                if ($derecha === 0.0) {
                    // Un índice compuesto con divisor cero no es infinito: es
                    // una fórmula que no aplica a este protocolo.
                    throw FormulaNoEvaluable::porDivisionEntreCero($expresion);
                }

                $izquierda /= $derecha;

                continue;
            }

            $izquierda *= $derecha;
        }

        return $izquierda;
    }

    /**
     * @param  array<string, float>  $valores
     *
     * @throws FormulaNoEvaluable
     */
    private function unario(array $valores, string $expresion): float
    {
        if ($this->siguienteEs('-')) {
            $this->posicion++;

            return -$this->unario($valores, $expresion);
        }

        if ($this->siguienteEs('+')) {
            $this->posicion++;

            return $this->unario($valores, $expresion);
        }

        return $this->atomo($valores, $expresion);
    }

    /**
     * @param  array<string, float>  $valores
     *
     * @throws FormulaNoEvaluable
     */
    private function atomo(array $valores, string $expresion): float
    {
        if ($this->posicion >= count($this->simbolos)) {
            throw FormulaNoEvaluable::porIncompleta($expresion);
        }

        $simbolo = $this->simbolos[$this->posicion];

        if ($simbolo['valor'] === '(') {
            $this->posicion++;
            $dentro = $this->suma($valores, $expresion);

            if (! $this->siguienteEs(')')) {
                throw FormulaNoEvaluable::porParentesis($expresion);
            }

            $this->posicion++;

            return $dentro;
        }

        $this->posicion++;

        if ($simbolo['tipo'] === 'numero') {
            return (float) $simbolo['valor'];
        }

        if ($simbolo['tipo'] === 'escala') {
            if (! array_key_exists($simbolo['valor'], $valores)) {
                throw FormulaNoEvaluable::porEscalaFaltante($expresion, $simbolo['valor']);
            }

            return $valores[$simbolo['valor']];
        }

        throw FormulaNoEvaluable::porSobrante($expresion, $simbolo['valor']);
    }

    private function siguienteEs(string $valor): bool
    {
        return isset($this->simbolos[$this->posicion])
            && $this->simbolos[$this->posicion]['valor'] === $valor;
    }
}
