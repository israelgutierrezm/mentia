<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Excepciones;

use RuntimeException;

class HabilitacionInvalida extends RuntimeException
{
    public static function porNoSerDominioPublico(string $instrumento): self
    {
        return new self(
            "«{$instrumento}» no es de dominio público: exige que la organización declare "
            .'su licencia antes de poder usarlo.'
        );
    }

    public static function porNoRequerirLicencia(string $instrumento): self
    {
        return new self(
            "«{$instrumento}» no requiere declaración de licencia. Habilítalo directo."
        );
    }

    public static function porDeclaracionVacia(): self
    {
        return new self(
            'La declaración de licencia no puede ir vacía: es la evidencia de quién asumió '
            .'la responsabilidad de usar contenido con copyright, y una casilla marcada no '
            .'sirve como tal.'
        );
    }

    public static function porFaltarDeclaracion(): self
    {
        return new self(
            'Este instrumento no se puede habilitar sin una declaración de licencia firmada.'
        );
    }

    public static function porNoHaberContenidoCapturado(): self
    {
        return new self(
            'Todavía no hay reactivos capturados para esta organización. Habilitarlo dejaría '
            .'asignable una prueba vacía.'
        );
    }

    public static function porNoEstarPublicada(string $version): self
    {
        return new self(
            "La versión {$version} está en borrador. Su contenido todavía puede cambiar, así "
            .'que una aplicación contra ella no sería reproducible.'
        );
    }
}
