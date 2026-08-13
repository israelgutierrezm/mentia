<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

/**
 * Leer cortes de la configuración y clasificar un puntaje en ellos.
 *
 * Los cortes viajan como texto: `0:nulo|5:bajo|9:medio|11:alto`. Cadena y no
 * JSON porque el valor del parámetro es una columna de texto y el principio de
 * cero JSON en dominio no se salta por comodidad; además así se lee de un
 * vistazo en un SELECT, que es donde alguien va a verificar si los cortes
 * cargados son los del DOF.
 */
trait ClasificaEnTramos
{
    /**
     * @return list<array{0: float, 1: string}>
     */
    protected function leerTramos(string $configuracion): array
    {
        $tramos = [];

        foreach (explode('|', $configuracion) as $tramo) {
            $partes = explode(':', $tramo, 2);

            if (count($partes) !== 2 || ! is_numeric(trim($partes[0]))) {
                continue;
            }

            $tramos[] = [(float) trim($partes[0]), trim($partes[1])];
        }

        usort($tramos, static fn (array $uno, array $otro): int => $uno[0] <=> $otro[0]);

        return $tramos;
    }

    /**
     * El tramo más alto cuyo mínimo alcanza el puntaje.
     *
     * @param  list<array{0: float, 1: string}>  $tramos
     */
    protected function clasificarEn(float $bruto, array $tramos): string
    {
        $etiqueta = $tramos[0][1];

        foreach ($tramos as [$minimo, $nombre]) {
            if ($bruto >= $minimo) {
                $etiqueta = $nombre;
            }
        }

        return $etiqueta;
    }
}
