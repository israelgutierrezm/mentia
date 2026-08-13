<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Excepciones;

use RuntimeException;

class BateriaInvalida extends RuntimeException
{
    public static function porInstrumentoNoHabilitado(string $instrumento): self
    {
        return new self(
            "«{$instrumento}» no está habilitado para esta organización. Una batería que lo "
            .'incluyera se armaría sin protestar y reventaría al asignarla, delante de la '
            .'persona que iba a contestarla.'
        );
    }

    public static function porNoAplicarseEnLinea(string $instrumento): self
    {
        return new self(
            "«{$instrumento}» no se aplica en línea: la editorial lo prohíbe. Sus resultados "
            .'se capturan, no se contestan desde una batería.'
        );
    }

    public static function porEstarVacia(): self
    {
        return new self(
            'Una batería sin instrumentos no se puede activar: quien la reciba no tendría '
            .'nada que contestar.'
        );
    }

    public static function porEstarEnUso(): self
    {
        return new self(
            'Esta batería tiene asignaciones activas. Cambiarla ahora haría que dos personas '
            .'contestaran la misma batería en secuencias distintas, y el orden afecta al '
            .'resultado. Archívala y crea otra.'
        );
    }

    public static function porOrdenIncompleto(): self
    {
        return new self(
            'El orden nuevo tiene que incluir exactamente los mismos renglones que la '
            .'batería. Una lista parcial dejaría posiciones viejas mezcladas con las nuevas.'
        );
    }
}
