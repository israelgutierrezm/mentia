<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Excepciones;

use RuntimeException;

class EstrategiaDesconocida extends RuntimeException
{
    /**
     * @param  list<string>  $conocidas
     */
    public static function para(string $clave, array $conocidas): self
    {
        sort($conocidas);

        return new self(sprintf(
            'No hay estrategia de calificación registrada con la clave «%s». Registradas: %s.',
            $clave,
            $conocidas === [] ? 'ninguna' : implode(', ', $conocidas),
        ));
    }

    public static function porEtapaEquivocada(string $clave, string $pedida, string $suya): self
    {
        return new self(sprintf(
            'La estrategia «%s» es de la etapa «%s» y el pipeline la configuró en «%s».',
            $clave,
            $suya,
            $pedida,
        ));
    }
}
