<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Excepciones;

use RuntimeException;

class ResultadoNoDisponible extends RuntimeException
{
    public static function porSerAnonima(): self
    {
        return new self(
            'Esta aplicación es anónima: no existe un resultado individual que entregar. '
            .'El agregado se consulta por asignación.'
        );
    }

    /**
     * El motivo real llega para la bitácora y para el log, NUNCA al usuario:
     * decirle a quien pregunta "esa persona está fuera de tu alcance" le
     * confirma que esa persona existe y está evaluada aquí (Doc 06).
     */
    public static function porFaltaDeAcceso(string $motivo): self
    {
        return new self($motivo);
    }

    public static function porNoEstarCalificada(): self
    {
        return new self('Esta aplicación todavía no tiene resultados calculados.');
    }
}
