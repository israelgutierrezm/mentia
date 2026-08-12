<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Servicios;

use App\Domain\Organizaciones\Excepciones\JerarquiaInvalida;
use App\Domain\Organizaciones\Modelos\Unidad;

/**
 * Alta y edición de unidades, con la jerarquía cuidada.
 */
class GestorUnidades
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Unidad
    {
        $padre = $this->padreValidado($datos);

        return Unidad::query()->create([
            'unidad_padre_id' => $padre?->id,
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'estado' => $datos['estado'] ?? 'activa',
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws JerarquiaInvalida
     */
    public function actualizar(Unidad $unidad, array $datos): Unidad
    {
        $padre = $this->padreValidado($datos);

        if ($padre !== null) {
            $this->exigirQueNoSeaCiclo($unidad, $padre);
        }

        $unidad->update([
            'unidad_padre_id' => $padre?->id,
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'estado' => $datos['estado'] ?? $unidad->estado,
        ]);

        return $unidad;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function padreValidado(array $datos): ?Unidad
    {
        $padreId = $datos['unidad_padre_id'] ?? null;

        if ($padreId === null) {
            return null;
        }

        /*
         * Se resuelve CON el global scope puesto. Es lo que impide colgar una
         * unidad de la jerarquía de otro tenant mandando su id en el
         * formulario: la consulta simplemente no lo encuentra.
         */
        return Unidad::query()->findOrFail($padreId);
    }

    /**
     * Una unidad no puede colgar de sí misma ni de su propia descendencia.
     *
     * Sin esto la base lo acepta —no hay CHECK que lo impida— y el resultado
     * es un ciclo: la rama entera desaparece del árbol y el cálculo de
     * descendientes, que es lo que decide el ALCANCE, se queda dando vueltas.
     *
     * @throws JerarquiaInvalida
     */
    private function exigirQueNoSeaCiclo(Unidad $unidad, Unidad $padre): void
    {
        if ($padre->id === $unidad->id) {
            throw JerarquiaInvalida::padreDeSiMisma();
        }

        if (in_array($padre->id, $unidad->idsConDescendientes(), true)) {
            throw JerarquiaInvalida::cicloDetectado();
        }
    }
}
