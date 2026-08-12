<?php

declare(strict_types=1);

namespace App\Domain\Personas;

use Illuminate\Support\ServiceProvider;

/**
 * Personas — M2.
 *
 * La identidad global y permanente, anclada en CURP. Es la entidad raíz del
 * sistema (principio P1): la persona NO pertenece a un tenant, su expediente
 * la acompaña entre organizaciones bajo su consentimiento.
 *
 * Incluye tutorías, vinculación a tenants vía `organizacion_personas` con
 * verificación de identidad, y la transición de mayoría de edad.
 *
 * Fase 1.
 */
class PersonasServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
