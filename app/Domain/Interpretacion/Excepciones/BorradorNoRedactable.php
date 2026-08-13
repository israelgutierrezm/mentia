<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Excepciones;

use RuntimeException;

/**
 * El integrador no pudo redactar.
 *
 * Todas fallan RUIDOSO. Devolver texto vacío produciría un reporte que parece
 * terminado y que nadie redactó, y alguien lo firmaría.
 */
class BorradorNoRedactable extends RuntimeException
{
    public static function porFaltarCredencial(): self
    {
        return new self(
            'No hay credencial de la API de IA configurada (ANTHROPIC_API_KEY). '
            .'El reporte integrador no se puede generar.'
        );
    }

    public static function porFalloDelProveedor(int $estado): self
    {
        return new self(sprintf(
            'El proveedor de IA respondió %d. El borrador no se generó.',
            $estado,
        ));
    }

    public static function porRespuestaVacia(): self
    {
        return new self('El proveedor de IA devolvió una respuesta vacía.');
    }
}
