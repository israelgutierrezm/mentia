<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Alertas\Contratos\NotificaAlertas;
use App\Domain\Alertas\Modelos\Alerta;
use Illuminate\Support\Facades\Log;

/**
 * Implementación PROVISIONAL de la Fase 6 (Doc 08: "AlertaService con
 * notificación stub").
 *
 * NO manda nada a nadie: deja el registro en el log y marca la alerta como
 * notificada. La real —destinatarios por rol y canal, correo, campana in-app—
 * es la Fase 8.
 *
 * IMPORTANTE, y por eso está escrito aquí: mientras esto sea el binding, una
 * ideación suicida detectada por un centinela **queda registrada pero no le
 * llega a nadie**. Es aceptable sólo porque el Doc 06 §5 ya exige que un tenant
 * registre su protocolo de actuación antes de poder habilitar instrumentos con
 * centinelas, y esa compuerta es de la Fase 8: hasta entonces, no debería haber
 * centinelas en producción.
 *
 * Esta clase se sustituye entera; no se le agrega lógica.
 */
class NotificacionRegistrada implements NotificaAlertas
{
    public function notificar(Alerta $alerta): void
    {
        Log::warning('ALERTA sin canal de notificación real (Fase 6)', [
            'alerta_id' => $alerta->id,
            'organizacion_id' => $alerta->organizacion_id,
            'severidad' => $alerta->severidad,
            'tipo' => $alerta->tipo,
            'aplicacion_id' => $alerta->aplicacion_id,

            // El mensaje NO lleva el contenido de la respuesta: un log es el
            // último lugar donde debe acabar lo que alguien contestó.
            'mensaje' => $alerta->mensaje,
        ]);

        $alerta->update(['estado' => 'notificada']);
    }
}
