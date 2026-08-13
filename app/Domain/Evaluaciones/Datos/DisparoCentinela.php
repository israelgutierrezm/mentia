<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Datos;

use App\Domain\Catalogo\Modelos\CentinelaCondicion;
use App\Domain\Evaluaciones\Modelos\Respuesta;

/**
 * Un centinela que se activó.
 */
final readonly class DisparoCentinela
{
    public function __construct(
        public Respuesta $respuesta,
        public CentinelaCondicion $condicion,
    ) {}

    public function severidad(): string
    {
        return $this->condicion->severidad;
    }

    /**
     * El mensaje para QUIEN ATIENDE, no para la persona evaluada.
     *
     * A ella se le muestra, al terminar, un mensaje cuidado con recursos de
     * apoyo — nunca "diste positivo a X" (Doc 05 §3).
     */
    public function mensaje(): string
    {
        return $this->condicion->mensaje;
    }
}
