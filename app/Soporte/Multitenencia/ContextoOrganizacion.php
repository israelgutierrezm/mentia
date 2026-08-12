<?php

declare(strict_types=1);

namespace App\Soporte\Multitenencia;

use App\Domain\Organizaciones\Modelos\Organizacion;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * La organización activa de la petición en curso.
 *
 * Singleton del contenedor. Lo alimenta el middleware de resolución de tenant
 * en web y API, y los jobs de cola que trabajan para una organización lo fijan
 * explícitamente —una cola no tiene petición HTTP, así que nadie se lo va a
 * poner solo—.
 *
 * Fijar el contexto hace DOS cosas a la vez, y por eso vive en un solo lugar:
 * alimenta los global scopes de Eloquent y el team id de Spatie. Si sólo se
 * hiciera una, los roles se resolverían contra un tenant y los datos contra
 * otro.
 */
class ContextoOrganizacion
{
    private ?Organizacion $organizacion = null;

    /**
     * Cuando es false, los global scopes de organización no filtran. Es para
     * la operación de plataforma —seeds, catálogo global, comandos de
     * mantenimiento— y se abre sólo dentro de sinRestriccion().
     */
    private bool $restringido = true;

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function establecer(Organizacion $organizacion): void
    {
        $this->organizacion = $organizacion;

        /*
         * Vía PermissionRegistrar y no con el helper global
         * setPermissionsTeamId(): el helper se define condicionalmente
         * (`if (! function_exists(...))`), así que otro paquete podría haberlo
         * declarado antes y estaríamos llamando a otra cosa.
         */
        $this->registrar->setPermissionsTeamId($organizacion->id);
    }

    public function limpiar(): void
    {
        $this->organizacion = null;

        $this->registrar->setPermissionsTeamId(null);
    }

    public function organizacion(): ?Organizacion
    {
        return $this->organizacion;
    }

    public function id(): ?int
    {
        return $this->organizacion?->id;
    }

    public function hayContexto(): bool
    {
        return $this->organizacion !== null;
    }

    public function restringido(): bool
    {
        return $this->restringido;
    }

    /**
     * Corre algo viendo TODAS las organizaciones.
     *
     * Es la única puerta para saltarse el aislamiento, y se cierra sola: el
     * `finally` restaura el estado aunque el callback lance. Sin eso, una
     * excepción a medio seed dejaría la petición entera sin aislamiento.
     *
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public function sinRestriccion(Closure $accion): mixed
    {
        $anterior = $this->restringido;
        $this->restringido = false;

        try {
            return $accion();
        } finally {
            $this->restringido = $anterior;
        }
    }
}
