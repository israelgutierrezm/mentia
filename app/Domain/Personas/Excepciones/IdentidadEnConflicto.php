<?php

declare(strict_types=1);

namespace App\Domain\Personas\Excepciones;

use RuntimeException;

/**
 * La CURP ya existe en la plataforma con OTRA fecha de nacimiento.
 *
 * Es un conflicto de identidad, no un error de captura cualquiera: o alguien
 * tecleó mal la fecha, o está intentando vincular a una persona que no es.
 * Ninguna de las dos se resuelve creando un segundo expediente —eso partiría
 * en dos la historia psicométrica de alguien— así que el alta se detiene y lo
 * resuelve una persona.
 */
class IdentidadEnConflicto extends RuntimeException
{
    public static function porFechaDistinta(string $curp): self
    {
        return new self(
            "La CURP {$curp} ya está registrada con otra fecha de nacimiento. "
            .'Verifica los datos antes de continuar.'
        );
    }
}
