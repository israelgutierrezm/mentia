<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Excepciones;

use RuntimeException;

/**
 * Una alerta que se intentó cerrar sin decir cómo se atendió.
 */
class AlertaSinResolucion extends RuntimeException
{
    public static function porSerDemasiadoBreve(): self
    {
        return new self(
            'Para cerrar la alerta hay que registrar qué se hizo. Una alerta que se '
            .'cierra con un clic no deja constancia de si alguien habló con la persona '
            .'o si sólo se quitó el punto rojo de la pantalla.'
        );
    }

    public static function porYaEstarCerrada(): self
    {
        return new self('Esta alerta ya está cerrada.');
    }
}
