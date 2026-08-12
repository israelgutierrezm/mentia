<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Excepciones;

use RuntimeException;

class JerarquiaInvalida extends RuntimeException
{
    public static function padreDeSiMisma(): self
    {
        return new self('Una unidad no puede depender de sí misma.');
    }

    public static function cicloDetectado(): self
    {
        return new self(
            'Esa unidad ya depende de la que intentas asignarle como superior. '
            .'El resultado sería un ciclo y la rama dejaría de existir en el árbol.'
        );
    }
}
