<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Excepciones;

use RuntimeException;

/**
 * Una fórmula derivada que no se pudo calcular.
 *
 * Siempre lleva la expresión completa: quien recibe el error tiene que poder
 * ir al catálogo y ver cuál de las fórmulas del instrumento es.
 */
class FormulaNoEvaluable extends RuntimeException
{
    public static function porCaracter(string $expresion, string $caracter): self
    {
        return new self(sprintf(
            'La fórmula «%s» tiene un carácter que no se admite: «%s».',
            $expresion,
            $caracter,
        ));
    }

    public static function porEscalaFaltante(string $expresion, string $clave): self
    {
        return new self(sprintf(
            'La fórmula «%s» cita la escala «%s», que no tiene puntaje calculado.',
            $expresion,
            $clave,
        ));
    }

    public static function porDivisionEntreCero(string $expresion): self
    {
        return new self(sprintf('La fórmula «%s» divide entre cero.', $expresion));
    }

    public static function porParentesis(string $expresion): self
    {
        return new self(sprintf('La fórmula «%s» tiene paréntesis sin cerrar.', $expresion));
    }

    public static function porIncompleta(string $expresion): self
    {
        return new self(sprintf('La fórmula «%s» está incompleta.', $expresion));
    }

    public static function porSobrante(string $expresion, string $simbolo): self
    {
        return new self(sprintf(
            'La fórmula «%s» tiene un «%s» de más.',
            $expresion,
            $simbolo,
        ));
    }
}
