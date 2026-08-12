<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Datos;

/**
 * Un permiso del sistema, con su etiqueta legible.
 *
 * La etiqueta y la descripción existen para la pantalla de roles: sin ellas, a
 * quien configura un rol se le presenta una lista de llaves técnicas
 * (`resultados.ver_detalle`) y termina concediendo cosas que no entendió.
 */
final readonly class Permiso
{
    public function __construct(
        public string $clave,
        public string $dominio,
        public string $etiqueta,
        public string $descripcion,
    ) {}
}
