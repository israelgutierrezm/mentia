<?php

declare(strict_types=1);

namespace App\Soporte\Multitenencia;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que acota una tabla de tenant a la organización activa.
 *
 * FALLA CERRADO: sin contexto de organización, la consulta no devuelve nada.
 *
 * La alternativa —no filtrar cuando no hay contexto— es la que produce la fuga
 * clásica: un comando de consola, un job mal armado o una ruta a la que se le
 * olvidó el middleware devuelven los datos de TODOS los tenants, y nada falla
 * ni se ve raro hasta que un cliente encuentra en su pantalla a los alumnos de
 * otra escuela. Una lista vacía se nota el primer día; una fuga, no.
 *
 * Lo que sí necesita ver todo —seeds, catálogo global, mantenimiento— lo pide
 * explícitamente con ContextoOrganizacion::sinRestriccion().
 */
class AlcanceOrganizacion implements Scope
{
    public function apply(Builder $consulta, Model $modelo): void
    {
        $contexto = app(ContextoOrganizacion::class);

        if (! $contexto->restringido()) {
            return;
        }

        $organizacionId = $contexto->id();

        if ($organizacionId === null) {
            $consulta->whereRaw('1 = 0');

            return;
        }

        $consulta->where($modelo->qualifyColumn('organizacion_id'), $organizacionId);
    }
}
