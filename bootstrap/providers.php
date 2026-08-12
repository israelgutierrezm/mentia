<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,

    /*
     * Un proveedor por dominio (Doc 02 §2). Cada uno declara sus bindings de
     * contrato => implementación; el orden es el de las fases del Doc 08, no
     * alfabético, porque así se lee la dependencia: Accesos necesita
     * Organizaciones y Personas, y Evaluaciones necesita Catálogo.
     */
    App\Domain\Organizaciones\OrganizacionesServiceProvider::class,
    App\Domain\Personas\PersonasServiceProvider::class,
    App\Domain\Accesos\AccesosServiceProvider::class,
    App\Domain\Expedientes\ExpedientesServiceProvider::class,
    App\Domain\Consentimientos\ConsentimientosServiceProvider::class,
    App\Domain\Catalogo\CatalogoServiceProvider::class,
    App\Domain\Evaluaciones\EvaluacionesServiceProvider::class,
    App\Domain\Interpretacion\InterpretacionServiceProvider::class,
    App\Domain\Alertas\AlertasServiceProvider::class,
];
