<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Contratos;

use App\Domain\Alertas\Modelos\Alerta;

/**
 * A quién y por dónde se avisa de una alerta.
 *
 * Contrato desde la Fase 6 con implementación provisional; la real —
 * destinatarios por rol y canal, correo, campana in-app, resolución
 * obligatoria— es la Fase 8 (Doc 08).
 */
interface NotificaAlertas
{
    public function notificar(Alerta $alerta): void;
}
