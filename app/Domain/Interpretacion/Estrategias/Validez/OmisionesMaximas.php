<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Validez;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 1 — `omisiones_max` (Doc 05 §2).
 *
 * Cuántos reactivos obligatorios quedaron sin responder. Un protocolo con la
 * mitad en blanco produce puntajes bajos que se leen como resultados y no lo
 * son: un puntaje bajo por omisión no significa lo mismo que uno bajo por
 * respuesta.
 *
 * Parámetros: `umbral_pct` (por omisión 20) y `al_exceder` (`dudosa` o
 * `invalida`, por omisión `invalida`).
 */
class OmisionesMaximas implements EstrategiaCalificacion
{
    public static function clave(): string
    {
        return 'omisiones_max';
    }

    public static function etapa(): string
    {
        return 'validez';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $esperados = $contexto->reactivosEsperados();
        $total = $esperados->count();

        if ($total === 0) {
            return;
        }

        $respondidos = $esperados
            ->filter(fn ($reactivo): bool => $contexto->respuestasDe($reactivo->id)->isNotEmpty())
            ->count();

        $omitidos = $total - $respondidos;
        $porcentaje = round(($omitidos / $total) * 100, 1);

        $umbral = (float) ($parametros['umbral_pct'] ?? 20);

        if ($porcentaje <= $umbral) {
            $contexto->anotarValidez(
                'omisiones',
                'paso',
                sprintf('%d de %d sin responder (%s%%).', $omitidos, $total, $porcentaje),
            );

            return;
        }

        // `dudosa` sigue el pipeline con advertencia; `invalida` lo detiene.
        $resultado = ($parametros['al_exceder'] ?? 'invalida') === 'dudosa'
            ? 'advertencia'
            : 'fallo';

        $contexto->anotarValidez(
            'omisiones',
            $resultado,
            sprintf(
                '%d de %d sin responder (%s%%), por encima del %s%% permitido.',
                $omitidos,
                $total,
                $porcentaje,
                $umbral,
            ),
        );
    }
}
