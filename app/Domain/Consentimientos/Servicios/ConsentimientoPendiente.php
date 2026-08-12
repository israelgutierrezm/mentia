<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Servicios;

use App\Domain\Consentimientos\Contratos\VerificaConsentimiento;
use App\Domain\Consentimientos\Datos\EstadoConsentimiento;
use App\Domain\Personas\Modelos\Persona;

/**
 * Implementación PROVISIONAL de la Fase 1 (Doc 08: "consentimiento aún como
 * stub que retorna pendiente").
 *
 * Devuelve siempre `Pendiente`: el módulo de consentimientos —textos
 * versionados con hash, evidencia, revocación, comparticiones— es la Fase 2.
 *
 * IMPORTANTE, y por eso está escrito aquí y no sólo en un ticket: `Pendiente`
 * DEJA PASAR. Es una compuerta abierta que la Fase 2 cierra. Lo que la hace
 * defendible mientras tanto es que cada acceso concedido así queda en bitácora
 * con `motivo` propio, así que al conectar la verificación real se puede
 * responder exactamente qué se consultó sin comprobar consentimiento, que es
 * justo lo que preguntaría una auditoría de la LFPDPPP.
 *
 * Esta clase se sustituye entera; no se le agrega lógica.
 */
class ConsentimientoPendiente implements VerificaConsentimiento
{
    public function estadoPara(
        Persona $sujeto,
        string $accion,
        ?int $propositoId,
        ?int $organizacionId,
    ): EstadoConsentimiento {
        return EstadoConsentimiento::Pendiente;
    }
}
