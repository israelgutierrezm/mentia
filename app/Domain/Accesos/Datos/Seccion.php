<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Datos;

/**
 * Una sección del sistema: dónde se hace algo y qué permiso hace falta.
 */
final readonly class Seccion
{
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public string $url,

        /** NULL = la ve cualquiera con sesión. */
        public ?string $permiso,

        public string $descripcion,

        /** Para agrupar el menú y las tarjetas del panel. */
        public string $grupo,

        /** Si lleva un contador de pendientes al lado. */
        public bool $conContador = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function paraLaVista(?int $contador = null): array
    {
        return [
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'url' => $this->url,
            'descripcion' => $this->descripcion,
            'grupo' => $this->grupo,
            'contador' => $contador,
        ];
    }
}
