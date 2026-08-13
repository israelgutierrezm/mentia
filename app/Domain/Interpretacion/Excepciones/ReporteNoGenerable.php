<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Excepciones;

use RuntimeException;

class ReporteNoGenerable extends RuntimeException
{
    public static function porFaltarPlantilla(string $tipo, string $audiencia): self
    {
        return new self(sprintf(
            'No hay plantilla vigente de reporte «%s» para la audiencia «%s».',
            $tipo,
            $audiencia,
        ));
    }

    public static function porFaltarOrganizacion(): self
    {
        return new self('No hay organización activa para generar el reporte.');
    }

    public static function porYaEstarFirmado(): self
    {
        return new self('Este reporte ya está firmado. Para corregirlo se genera uno nuevo.');
    }

    /**
     * Firmar dice "yo respondo por esto". Firmar texto que redactó una IA y que
     * nadie leyó es exactamente lo que el Doc 05 §6 prohíbe.
     */
    public static function porBorradorSinValidar(): self
    {
        return new self(
            'Este reporte lleva un borrador redactado por IA que todavía nadie validó. '
            .'Valídalo antes de firmar.'
        );
    }

    public static function porArchivoPerdido(string $uuid): self
    {
        return new self(sprintf('El PDF del reporte %s no está en el almacenamiento.', $uuid));
    }
}
