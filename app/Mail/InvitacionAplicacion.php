<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Evaluaciones\Datos\Invitacion;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La invitación a contestar.
 *
 * NO implementa ShouldQueue a propósito: lleva el token en claro, y encolarla
 * lo dejaría escrito en la tabla `jobs` y en los paneles de Horizon. Quien
 * quiera mandar trescientas en segundo plano encola el ENVÍO COMPLETO —que
 * genera los tokens dentro del job— no este objeto.
 *
 * El correo NO dice qué instrumento es. Un asunto que diga "Contesta tu PHQ-9"
 * revela una evaluación de salud mental a quien mire la bandeja de entrada por
 * encima del hombro.
 */
class InvitacionAplicacion extends Mailable
{
    public function __construct(public readonly Invitacion $invitacion) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitacion->asunto());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'correo.invitacion-aplicacion',
            with: [
                'nombre' => $this->invitacion->paraQuien->nombres,
                'liga' => $this->invitacion->liga(),
                'vence' => $this->invitacion->destinatario->asignacion->ventana_fin,
                'esRecordatorio' => $this->invitacion->esRecordatorio,
                'sobreQuien' => $this->invitacion->destinatario->quien_responde_persona_id !== null
                    ? $this->invitacion->destinatario->persona->nombres
                    : null,
            ],
        );
    }
}
