<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Datos;

use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Personas\Modelos\Persona;

/**
 * Lo que un canal necesita para mandar una invitación.
 *
 * Lleva el token EN CLARO porque es la única vez que existe. Por eso este
 * objeto no se serializa ni se encola: un job con el token en su payload lo
 * dejaría escrito en la tabla `jobs` y en los logs de Horizon.
 */
final readonly class Invitacion
{
    public function __construct(
        public AsignacionDestinatario $destinatario,
        public Persona $paraQuien,
        public string $tokenClaro,
        public bool $esRecordatorio = false,
    ) {}

    /**
     * A quién se le escribe: al informante si lo hay, si no a la propia
     * persona. La madre que contesta el M-CHAT recibe el correo, no el niño.
     */
    public static function para(
        AsignacionDestinatario $destinatario,
        string $tokenClaro,
        bool $esRecordatorio = false,
    ): self {
        $quien = $destinatario->quienResponde ?? $destinatario->persona;

        return new self($destinatario, $quien, $tokenClaro, $esRecordatorio);
    }

    public function liga(): string
    {
        return url('/aplicacion/'.$this->tokenClaro);
    }

    public function asunto(): string
    {
        return $this->esRecordatorio
            ? 'Recordatorio: tienes una evaluación pendiente'
            : 'Tienes una evaluación por contestar';
    }
}
