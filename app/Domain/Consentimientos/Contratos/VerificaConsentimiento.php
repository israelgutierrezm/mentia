<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Contratos;

use App\Domain\Consentimientos\Datos\EstadoConsentimiento;
use App\Domain\Personas\Modelos\Persona;

/**
 * La cuarta dimensión de la autorización (Doc 06 §1).
 *
 * Vive en Consentimientos y no en Accesos, y se consume como CONTRATO, porque
 * la implementación real llega en la Fase 2 (textos versionados, evidencia,
 * revocación, comparticiones cross-tenant). AccesoService no debe cambiar
 * cuando eso ocurra: sólo se sustituye el binding del ServiceProvider.
 */
interface VerificaConsentimiento
{
    /**
     * ¿Hay consentimiento vigente que ampare este acceso?
     *
     * @param  Persona  $sujeto  Sobre quién se quiere actuar.
     * @param  string  $accion  Permiso solicitado.
     * @param  int|null  $propositoId  Para qué (plantilla de propósito, M6).
     * @param  int|null  $organizacionId  Tenant que pregunta.
     */
    public function estadoPara(
        Persona $sujeto,
        string $accion,
        ?int $propositoId,
        ?int $organizacionId,
    ): EstadoConsentimiento;
}
