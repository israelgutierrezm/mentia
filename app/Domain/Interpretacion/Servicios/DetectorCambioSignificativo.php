<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Modelos\UmbralCambio;
use App\Soporte\Multitenencia\ContextoOrganizacion;

/**
 * Comparador persona ↔ sí misma (Doc 05 §4).
 *
 * Recorre la serie de un constructo y marca los saltos que salen del error de
 * medida. Que un percentil suba de 40 a 45 no es noticia: es ruido. Marcarlo
 * todo tendría el mismo efecto que no marcar nada, porque nadie mira una lista
 * en la que todo está marcado.
 *
 * SÓLO SE COMPARA CONTRA EL MISMO TIPO DE NORMA. Un percentil y una puntuación
 * T no son la misma regla: restarlos daría una diferencia que parece grande y
 * no significa nada. Un cambio de norma entre dos mediciones se reporta como
 * tal, no como un cambio de la persona.
 */
class DetectorCambioSignificativo
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * @return list<array{desde: string, hasta: string, valor_anterior: float, valor_actual: float, delta: float, significativo: bool, direccion: string}>
     */
    public function serieDe(int $personaId, string $constructo): array
    {
        $puntos = ResultadoNormalizado::query()
            ->serieDe($personaId, $constructo)
            ->get();

        if ($puntos->count() < 2) {
            return [];
        }

        $cambios = [];
        $anterior = null;

        foreach ($puntos as $punto) {
            if ($anterior === null) {
                $anterior = $punto;

                continue;
            }

            if ($anterior->tipo_norma !== $punto->tipo_norma) {
                /*
                 * Cambió la norma entre una medición y la otra. No se compara:
                 * se salta y el punto nuevo pasa a ser la referencia. Restar un
                 * percentil de una T produce un número grande que no describe
                 * nada de la persona.
                 */
                $anterior = $punto;

                continue;
            }

            $delta = $punto->valor - $anterior->valor;
            $umbral = $this->umbralDe($constructo, $punto->tipo_norma);

            $cambios[] = [
                'desde' => $anterior->fecha->toDateString(),
                'hasta' => $punto->fecha->toDateString(),
                'valor_anterior' => $anterior->valor,
                'valor_actual' => $punto->valor,
                'delta' => round($delta, 3),
                'significativo' => abs($delta) >= $umbral,
                'direccion' => $delta > 0 ? 'sube' : ($delta < 0 ? 'baja' : 'igual'),
            ];

            $anterior = $punto;
        }

        return $cambios;
    }

    /**
     * El umbral que aplica: el de la organización si lo configuró, si no el de
     * la plataforma.
     *
     * El de fábrica es una desviación estándar de la escala en que se mide. No
     * es un número redondo elegido al azar: es el punto donde la diferencia
     * empieza a ser mayor que el error de medición típico de un instrumento
     * psicométrico.
     */
    public function umbralDe(string $constructo, string $tipoNorma): float
    {
        $organizacionId = $this->contexto->id();

        $delTenant = $organizacionId === null
            ? null
            : UmbralCambio::query()
                ->where('organizacion_id', $organizacionId)
                ->where('constructo', $constructo)
                ->where('tipo_norma', $tipoNorma)
                ->value('delta_minimo');

        if ($delTenant !== null) {
            return (float) $delTenant;
        }

        $global = UmbralCambio::query()
            ->whereNull('organizacion_id')
            ->where('constructo', $constructo)
            ->where('tipo_norma', $tipoNorma)
            ->value('delta_minimo');

        if ($global !== null) {
            return (float) $global;
        }

        return UmbralCambio::POR_OMISION[$tipoNorma] ?? 10.0;
    }
}
