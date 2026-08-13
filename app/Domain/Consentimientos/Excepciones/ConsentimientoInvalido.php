<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Excepciones;

use RuntimeException;

class ConsentimientoInvalido extends RuntimeException
{
    public static function porOtorganteSinFacultad(): self
    {
        return new self(
            'Sólo el titular o un tutor con tutela acreditada pueden otorgar este '
            .'consentimiento. Ni el profesional ni el administrador firman por la persona.'
        );
    }

    public static function porTextoAlterado(int $textoId): self
    {
        return new self(
            "El texto de consentimiento {$textoId} no coincide con su hash: fue modificado "
            .'fuera de la aplicación. No se puede ligar una firma a un texto que ya no es el '
            .'que se publicó.'
        );
    }

    public static function porNoEstarVigente(): self
    {
        return new self('Ese consentimiento no está vigente, así que no ampara nada.');
    }
}
