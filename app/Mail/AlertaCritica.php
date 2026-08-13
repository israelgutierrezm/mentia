<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El aviso de una alerta de riesgo.
 *
 * NO LLEVA EL CONTENIDO DE LA RESPUESTA. Dice que hay una alerta y dónde
 * verla, y nada más. Un correo que dijera «contestó que sí a la pregunta de
 * ideación suicida» viaja por servidores que no son de nadie, se queda en la
 * bandeja de entrada de quien lo reciba para siempre y se reenvía sin pensarlo.
 * El detalle se ve dentro del sistema, donde el acceso pasa por AccesoService y
 * queda en bitácora.
 *
 * Tampoco dice el nombre de la persona ni el del instrumento, por lo mismo: el
 * asunto de un correo lo lee cualquiera que pase por detrás de la pantalla.
 *
 * No implementa `ShouldQueue`: una alerta crítica que espera su turno detrás de
 * doscientos correos de invitación llega cuando ya no sirve.
 */
class AlertaCritica extends Mailable
{
    public function __construct(
        public readonly Alerta $alerta,
        public readonly Persona $paraQuien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->alerta->severidad) {
            'critica' => 'Alerta crítica: requiere atención inmediata',
            'alta' => 'Alerta de riesgo: requiere atención',
            default => 'Aviso de seguimiento',
        });
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'correo.alerta-critica',
            with: [
                'nombre' => $this->paraQuien->nombres,
                'severidad' => $this->alerta->severidad,
                'creadaEn' => $this->alerta->creada_en,
                'liga' => url('/alertas'),
            ],
        );
    }
}
