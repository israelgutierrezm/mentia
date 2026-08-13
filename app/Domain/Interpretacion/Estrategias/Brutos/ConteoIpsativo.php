<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 2 — `conteo_ipsativo` (Doc 05 §2): Cleaver, Kostick, DISC.
 *
 * En cada cuadro se marca la opción que MÁS describe y la que MENOS. La misma
 * opción puntúa distinto según el papel que se le dio, y por eso
 * `claves_calificacion` lleva `rol`: la clave con rol `mas` dice a qué escala
 * suma cuando se eligió como "más", y la de rol `menos` a cuál cuando se
 * descartó.
 *
 * Salen así los dos perfiles del Cleaver —M (bajo presión) y L (natural)— que
 * luego una fórmula derivada resta para obtener T. Esa resta NO va aquí: es una
 * `formulas_derivadas`, y meterla dentro convertiría esta estrategia en la
 * estrategia del Cleaver en vez de la de todos los ipsativos.
 */
class ConteoIpsativo extends EstrategiaDeBrutos
{
    public static function clave(): string
    {
        return 'conteo_ipsativo';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claves = $this->clavesPorReactivo($contexto);

        foreach ($contexto->respuestas as $respuesta) {
            if ($respuesta->opcion_id === null || $respuesta->rol_ipsativo === null) {
                continue;
            }

            foreach ($claves[$respuesta->reactivo_id] ?? [] as $clave) {
                if ($clave->opcion_id !== $respuesta->opcion_id) {
                    continue;
                }

                // El rol de la clave tiene que coincidir con el papel que la
                // persona le dio a la opción.
                if ($clave->rol !== $respuesta->rol_ipsativo) {
                    continue;
                }

                $this->acumular($contexto, $clave->escala_id, (float) $clave->peso);
            }
        }

        $this->completarEnCero($contexto);
    }
}
