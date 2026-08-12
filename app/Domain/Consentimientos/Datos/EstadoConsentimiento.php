<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Datos;

enum EstadoConsentimiento: string
{
    /** Hay consentimiento vigente que ampara el propósito. */
    case Amparado = 'amparado';

    /** Se pidió, se revocó o venció. Niega el acceso. */
    case NoAmparado = 'no_amparado';

    /**
     * Todavía no se puede saber: el módulo de consentimientos no existe
     * (Fase 2). NO es lo mismo que "amparado", y por eso es un caso propio:
     * AccesoService lo deja pasar pero lo escribe distinto en bitácora, así
     * que al conectar la verificación real se puede consultar exactamente qué
     * accesos se concedieron sin comprobarla.
     */
    case Pendiente = 'pendiente';

    public function permiteContinuar(): bool
    {
        return $this !== self::NoAmparado;
    }
}
