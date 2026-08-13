<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Modelos\PerfilPuesto;
use App\Domain\Interpretacion\Modelos\PerfilPuestoCriterio;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;

/**
 * Comparador persona ↔ puesto (Doc 05 §4).
 *
 * Devuelve un porcentaje de ajuste Y EL DETALLE POR CRITERIO. El detalle no es
 * adorno: un 72% sin decir en qué criterio se falló no sirve para decidir nada,
 * y en selección esa decisión afecta el trabajo de alguien. Quien lea esto tiene
 * que poder ver que el candidato queda fuera de rango justo en la escala que
 * menos importa, o justo en la que más.
 *
 * Los criterios SIN dato no cuentan como fallo: se reportan aparte. Un
 * candidato al que no se le aplicó el instrumento de una escala no "falló" ese
 * criterio, y contarlo como cero lo hundiría por algo que nadie le preguntó.
 */
class ComparadorPuesto
{
    /**
     * @param  list<Aplicacion>  $aplicaciones  Las que se consideran del candidato.
     * @return array{ajuste_pct: float, criterios: list<array<string, mixed>>, sin_dato: int}
     */
    public function comparar(PerfilPuesto $perfil, array $aplicaciones): array
    {
        $ids = array_map(static fn (Aplicacion $aplicacion): int => $aplicacion->id, $aplicaciones);

        $resultados = ResultadoEscala::query()
            ->whereIn('aplicacion_id', $ids)
            ->get();

        $criterios = [];
        $pesoTotal = 0.0;
        $pesoCumplido = 0.0;
        $sinDato = 0;

        foreach ($perfil->criterios()->with('escala')->get() as $criterio) {
            $valor = $this->valorDe($resultados, $criterio);

            if ($valor === null) {
                $sinDato++;

                $criterios[] = [
                    'escala' => $criterio->escala->clave,
                    'esperado' => $this->rango($criterio),
                    'obtenido' => null,
                    'cumple' => null,
                    'ponderacion' => $criterio->ponderacion,
                ];

                continue;
            }

            $cumple = $criterio->cumple($valor);

            $pesoTotal += $criterio->ponderacion;

            if ($cumple) {
                $pesoCumplido += $criterio->ponderacion;
            }

            $criterios[] = [
                'escala' => $criterio->escala->clave,
                'esperado' => $this->rango($criterio),
                'obtenido' => $valor,
                'cumple' => $cumple,
                'ponderacion' => $criterio->ponderacion,
            ];
        }

        return [
            /*
             * El porcentaje se calcula sobre los criterios QUE SE PUDIERON
             * evaluar. Repartirlo sobre todos castigaría al candidato por
             * pruebas que la organización no le aplicó.
             */
            'ajuste_pct' => $pesoTotal > 0.0
                ? round(($pesoCumplido / $pesoTotal) * 100, 1)
                : 0.0,
            'criterios' => $criterios,
            'sin_dato' => $sinDato,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ResultadoEscala>  $resultados
     */
    private function valorDe($resultados, PerfilPuestoCriterio $criterio): ?float
    {
        $delaEscala = $resultados->where('escala_id', $criterio->escala_id);

        if ($delaEscala->isEmpty()) {
            return null;
        }

        // Con varias aplicaciones de la misma escala gana LA MÁS RECIENTE: en
        // selección se decide con lo que la persona es hoy.
        $resultado = $delaEscala->sortByDesc('calculado_en')->first();

        return $resultado?->valorEnTipo($criterio->tipo_puntaje);
    }

    /**
     * @return array{min: float|null, max: float|null, tipo: string}
     */
    private function rango(PerfilPuestoCriterio $criterio): array
    {
        return [
            'min' => $criterio->valor_min,
            'max' => $criterio->valor_max,
            'tipo' => $criterio->tipo_puntaje,
        ];
    }
}
