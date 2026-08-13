<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Contratos;

use App\Domain\Evaluaciones\Datos\Invitacion;

/**
 * Por dónde llega la liga de aplicación.
 *
 * Abstraído desde ahora aunque la V1 sólo mande correo: el Doc 01 §4 describe
 * padres respondiendo el M-CHAT por WhatsApp, y el Doc 02 §5 nombra correo,
 * WhatsApp y app. Cablear `Mail::to()` en el servicio de asignaciones haría
 * que agregar WhatsApp obligara a tocar la lógica de asignación, que no tiene
 * nada que ver.
 */
interface CanalNotificacion
{
    public function enviar(Invitacion $invitacion): void;

    /**
     * La clave del canal, para bitácora y para elegirlo por configuración.
     */
    public function clave(): string;
}
