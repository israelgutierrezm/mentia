<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Excepciones;

use RuntimeException;

class AsignacionInvalida extends RuntimeException
{
    public static function porInstrumentoYBateria(): self
    {
        return new self(
            'Una asignación lleva un instrumento O una batería, exactamente uno. Con los dos '
            .'—o con ninguno— el motor de aplicación no sabría qué presentar.'
        );
    }

    public static function porNoTenerDestinatarios(): self
    {
        return new self(
            'La asignación no alcanzó a ninguna persona. Si viene de una agrupación, '
            .'comprueba que tenga miembros con membresía vigente.'
        );
    }

    public static function porVentanaInvertida(): self
    {
        return new self(
            'La ventana termina antes de empezar: nadie podría contestar y nada lo explicaría.'
        );
    }

    public static function porNoEstarActiva(string $estado): self
    {
        return new self("La asignación está en «{$estado}» y ya no admite cambios.");
    }

    /**
     * Requisito ético del Doc 06 §5, no burocrático.
     *
     * Un instrumento con reactivos centinela detecta ideación suicida.
     * Asignarlo sin haber definido quién responde y en cuánto tiempo produce
     * una alerta crítica a las once de la noche en un buzón que nadie mira
     * hasta el lunes.
     */
    public static function porFaltarProtocolo(string $motivo): self
    {
        return new self($motivo);
    }

    public static function porSerAnonima(): self
    {
        return new self(
            'Esta asignación es anónima. El vínculo entre persona y respuesta no existe por '
            .'diseño y no se puede reconstruir: es lo que hace creíble el anonimato.'
        );
    }
}
