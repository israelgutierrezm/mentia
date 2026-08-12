<?php

declare(strict_types=1);

namespace App\Soporte\Reglas;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Formato oficial de CURP (RENAPO), 18 caracteres.
 *
 * Comprueba la ESTRUCTURA, no el dígito verificador: la regla del dígito
 * cambió con el tiempo y hay CURPs emitidas y vigentes que no la cumplen.
 * Rechazar una CURP real porque nuestro algoritmo es más nuevo que el
 * documento deja a una persona fuera del sistema; el ancla de identidad de
 * verdad es CURP + fecha de nacimiento juntas, que es lo que comprueba el alta.
 */
class Curp implements ValidationRule
{
    private const PATRON = '/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La CURP debe ser texto.')->translate();

            return;
        }

        if (preg_match(self::PATRON, strtoupper($value)) !== 1) {
            $fail('La CURP debe tener 18 caracteres con el formato oficial de RENAPO.');
        }
    }
}
