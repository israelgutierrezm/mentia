<?php

declare(strict_types=1);

namespace App\Domain\Personas\Excepciones;

use RuntimeException;

class TutoriaInvalida extends RuntimeException
{
    public static function porAutoValidacion(): self
    {
        return new self(
            'Nadie valida su propia tutoría. Acreditar el parentesco es un acto de un '
            .'profesional de la organización, no de quien lo declara.'
        );
    }

    public static function porSerLaMismaPersona(): self
    {
        return new self('Una persona no puede ser tutora de sí misma.');
    }

    public static function porNoEstarPendiente(string $estado): self
    {
        return new self(
            "Sólo se valida una tutoría pendiente; ésta está en «{$estado}». "
            .'Para reactivar una revocada se registra una nueva.'
        );
    }

    public static function porMenorFueraDelTenant(): self
    {
        return new self(
            'Esa persona no está vinculada a esta organización, así que aquí no se le '
            .'puede acreditar una tutoría.'
        );
    }
}
