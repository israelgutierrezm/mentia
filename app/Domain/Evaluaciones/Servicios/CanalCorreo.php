<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Contratos\CanalNotificacion;
use App\Domain\Evaluaciones\Datos\Invitacion;
use App\Mail\InvitacionAplicacion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Canal de correo. El único de la V1.
 */
class CanalCorreo implements CanalNotificacion
{
    public function enviar(Invitacion $invitacion): void
    {
        $correo = $invitacion->paraQuien->usuario?->email;

        if ($correo === null) {
            /*
             * Sin correo no se puede invitar, pero tampoco se revienta: en una
             * asignación de trescientas personas, una sin cuenta no puede
             * tumbar el envío de las otras doscientas noventa y nueve. Queda en
             * el log para que alguien lo resuelva.
             */
            Log::warning('Destinatario sin correo', [
                'destinatario_id' => $invitacion->destinatario->id,
                'persona_id' => $invitacion->paraQuien->id,
            ]);

            return;
        }

        Mail::to($correo)->send(new InvitacionAplicacion($invitacion));
    }

    public function clave(): string
    {
        return 'correo';
    }
}
