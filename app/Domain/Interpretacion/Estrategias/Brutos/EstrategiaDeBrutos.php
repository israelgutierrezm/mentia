<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Brutos;

use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Lo que comparten las estrategias de la etapa 2.
 *
 * Todas hacen lo mismo en el fondo: recorrer respuestas, mirar las CLAVES de
 * calificación y acumular por escala. Lo que cambia es qué considera "un punto"
 * cada una.
 */
abstract class EstrategiaDeBrutos implements EstrategiaCalificacion
{
    public static function etapa(): string
    {
        return 'brutos';
    }

    /**
     * Las claves de la versión, agrupadas por reactivo.
     *
     * Se cargan de una sola consulta: hacerlo por reactivo en un instrumento de
     * ciento veinte reactivos son ciento veinte consultas por aplicación, y las
     * aplicaciones se califican por miles.
     *
     * @return array<int, list<ClaveCalificacion>>
     */
    protected function clavesPorReactivo(ContextoCalificacion $contexto): array
    {
        $agrupadas = [];

        $claves = ClaveCalificacion::query()
            ->where('version_instrumento_id', $contexto->aplicacion->version_instrumento_id)
            ->get();

        foreach ($claves as $clave) {
            $agrupadas[$clave->reactivo_id][] = $clave;
        }

        return $agrupadas;
    }

    /**
     * Acumula sobre lo que ya haya, sin pisar.
     *
     * Un instrumento puede correr dos estrategias de brutos —un conteo de
     * criterio para las escalas de riesgo y una suma para las de gravedad— y la
     * segunda no puede borrar lo que dejó la primera.
     */
    protected function acumular(ContextoCalificacion $contexto, int $escalaId, float $cuanto): void
    {
        $contexto->anotarBruto($escalaId, ($contexto->brutos[$escalaId] ?? 0.0) + $cuanto);
    }

    /**
     * Deja en cero las escalas que ninguna respuesta tocó.
     *
     * Sin esto, una escala en la que la persona no marcó nada simplemente no
     * existiría en los resultados, y la diferencia entre "cero" y "no se
     * calculó" desaparecería justo donde importa.
     */
    protected function completarEnCero(ContextoCalificacion $contexto): void
    {
        foreach ($contexto->escalas as $escala) {
            if (! isset($contexto->brutos[$escala->id])) {
                $contexto->anotarBruto($escala->id, 0.0);
            }
        }
    }

    /**
     * El valor numérico de una respuesta, con la REFLEXIÓN aplicada si el
     * reactivo es inverso.
     *
     * «No me cuesta trabajo concentrarme» puntúa al revés que «Me cuesta
     * trabajo concentrarme»: sin reflejar, los dos sumarían en la misma
     * dirección y la escala mediría ruido. La fórmula es (max + min) − valor
     * sobre el dominio de las opciones del propio reactivo, no sobre un rango
     * fijo: un likert de 4 y uno de 5 no se reflejan igual.
     */
    protected function valorReflejado(Reactivo $reactivo, float $valor): float
    {
        if (! $reactivo->es_inverso) {
            return $valor;
        }

        $codigos = OpcionReactivo::query()
            ->where('reactivo_id', $reactivo->id)
            ->pluck('codigo')
            ->map(static fn (string $codigo): ?float => is_numeric($codigo) ? (float) $codigo : null)
            ->filter(static fn (?float $numero): bool => $numero !== null)
            ->values();

        if ($codigos->isEmpty()) {
            return $valor;
        }

        return ((float) $codigos->max() + (float) $codigos->min()) - $valor;
    }
}
