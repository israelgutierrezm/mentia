<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Excepciones;

use RuntimeException;

class CapturaNoPermitida extends RuntimeException
{
    public static function porRol(string $etiqueta, string $quienPuede): self
    {
        return new self(
            "«{$etiqueta}» sólo lo puede capturar: {$quienPuede}."
        );
    }

    public static function porCampoInactivo(string $clave): self
    {
        return new self(
            "El campo «{$clave}» está desactivado y ya no admite capturas nuevas. "
            .'Los valores que ya tenía se conservan.'
        );
    }
}
