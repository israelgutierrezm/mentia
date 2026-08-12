<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos;

use Illuminate\Support\ServiceProvider;

/**
 * Consentimientos — M4.
 *
 * Compuerta legal del sistema (principio P7): ningún permiso abre datos sin
 * consentimiento vigente que ampare el propósito concreto.
 *
 * Textos versionados con hash SHA-256, consentimientos de titular y de tutor
 * con evidencia y vigencia, revocación con efecto inmediato y comparticiones
 * de expediente entre tenants —que decide la persona, no la organización—.
 *
 * Vive aparte de Expedientes porque quien pregunta por un consentimiento es
 * Accesos, no el expediente: separarlos evita la dependencia circular.
 *
 * Fase 2.
 */
class ConsentimientosServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
