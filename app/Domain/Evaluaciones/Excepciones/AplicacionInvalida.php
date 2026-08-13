<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Excepciones;

use RuntimeException;

class AplicacionInvalida extends RuntimeException
{
    public static function porVentanaCerrada(): self
    {
        return new self(
            'La ventana de esta asignación ya cerró. Contestar fuera de plazo haría que los '
            .'resultados dejaran de ser comparables con el resto de la campaña.'
        );
    }

    public static function porNoAdmitirRespuestas(string $estado): self
    {
        return new self("La aplicación está en «{$estado}» y ya no admite respuestas.");
    }

    public static function porNoEstarEnCurso(string $estado): self
    {
        return new self("La aplicación está en «{$estado}», no en curso.");
    }

    public static function porBloqueDesconocido(string $clave): self
    {
        return new self("El bloque «{$clave}» no pertenece a esta aplicación.");
    }

    public static function porNoQuedarIntentos(): self
    {
        return new self(
            'Se agotaron los intentos permitidos para este instrumento en esta asignación.'
        );
    }

    public static function porFaltarInstrumentoDeBateria(): self
    {
        return new self(
            'La asignación es de una batería: hay que decir cuál de sus instrumentos se va a '
            .'contestar. Adivinar el primero haría que recargar la pantalla arrancara siempre '
            .'el mismo.'
        );
    }
}
