<?php

declare(strict_types=1);

namespace App\Domain\Accesos;

use Illuminate\Support\ServiceProvider;

/**
 * Accesos — M3.
 *
 * PUNTO ÚNICO DE AUTORIZACIÓN de recursos de personas (Doc 02 §2, regla 2).
 * `AccesoService::autorizar(actor, accion, sujeto, recurso, proposito)`
 * resuelve en cortocircuito las cuatro dimensiones —permiso de Spatie,
 * alcance, nivel de sensibilidad y consentimiento vigente— y registra la
 * decisión en bitácora en el mismo acto, autorice o niegue.
 *
 * Ningún controller puede replicar estas verificaciones por su cuenta: si un
 * endpoint toca datos de una persona, pasa por aquí.
 *
 * Fase 1 (con la verificación de consentimiento como contrato con
 * implementación provisional; la real llega en la Fase 2).
 */
class AccesosServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
