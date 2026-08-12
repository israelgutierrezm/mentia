<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones;

use Illuminate\Support\ServiceProvider;

/**
 * Organizaciones — M1.
 *
 * Los tenants y su estructura interna: tipo_organizacion (escuela, empresa,
 * consultorio, dependencia), unidades jerárquicas, agrupaciones tipificadas y
 * membresías con vigencia.
 *
 * La organización es el discriminador multi-tenant de todo el sistema: su id
 * es el `organizacion_id` que llevan las tablas de tenant y el que Spatie usa
 * como team_foreign_key (Doc 02 §3).
 *
 * Fase 1.
 */
class OrganizacionesServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
