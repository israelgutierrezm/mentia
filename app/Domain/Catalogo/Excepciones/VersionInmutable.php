<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Excepciones;

use RuntimeException;

/**
 * Se intentó escribir contenido de una versión que ya no admite edición.
 */
class VersionInmutable extends RuntimeException
{
    public static function porEstarPublicada(string $instrumento, string $version): self
    {
        return new self(
            "La versión {$version} de «{$instrumento}» está publicada y su contenido no se "
            .'edita. Una aplicación de hace dos años apunta a esta versión exacta: si '
            .'cambiara, su resultado dejaría de ser reproducible. Publica una versión nueva.'
        );
    }

    public static function porEstarRetirada(string $instrumento, string $version): self
    {
        return new self(
            "La versión {$version} de «{$instrumento}» está retirada. Se retiró justamente "
            .'para congelarla.'
        );
    }

    public static function porNoTenerContenido(string $instrumento): self
    {
        return new self(
            "«{$instrumento}» no tiene reactivos: publicar una versión vacía dejaría un "
            .'instrumento asignable que nadie puede contestar.'
        );
    }

    public static function porEscalaSinClaves(string $escala): self
    {
        return new self(
            "La escala «{$escala}» no tiene ninguna clave de calificación. Al aplicarse, "
            .'siempre puntuaría cero y nadie sabría por qué.'
        );
    }

    public static function porFormulaInvalida(string $expresion, string $motivo): self
    {
        return new self("La fórmula «{$expresion}» no es válida: {$motivo}");
    }
}
