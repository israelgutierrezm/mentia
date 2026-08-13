<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Catalogo\Modelos\Baremo;
use App\Domain\Catalogo\Modelos\BaremoFila;
use App\Domain\Evaluaciones\Modelos\Aplicacion;

/**
 * Etapa 4 — qué baremo se usa y qué dice (Doc 05 §2).
 *
 * LA PRIORIDAD ES EL PUNTO: tenant → nacional → global. Una empresa con diez
 * mil aplicaciones propias tiene mejor norma para su gente que la tabla
 * publicada en un manual de 1998 con universitarios de otro país; y una norma
 * mexicana describe mejor a un adolescente de Oaxaca que una estadounidense.
 * Tomar el primero que aparezca sería normalizar contra quien no se parece.
 *
 * Si no hay baremo aplicable el resultado se queda en bruto con `sin_norma`, y
 * NO se inventa un percentil. Un número normalizado contra nada se lee igual
 * que uno bueno.
 */
class ResolutorBaremo
{
    /**
     * La capa de baremo que aplica, o null si no hay ninguna.
     */
    public function resolver(Aplicacion $aplicacion, int $escalaId): ?Baremo
    {
        $candidatos = Baremo::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->where('escala_id', $escalaId)
            ->where('vigente', true)
            ->with('poblacion')
            ->get();

        if ($candidatos->isEmpty()) {
            return null;
        }

        // 1. Del propio tenant.
        $delTenant = $candidatos->firstWhere('organizacion_id', $aplicacion->organizacion_id);

        if ($delTenant instanceof Baremo) {
            return $delTenant;
        }

        /*
         * 2. Nacional: la población del país donde opera la plataforma.
         *
         * Sale de configuración y no de la organización porque `organizaciones`
         * no guarda país: mientras Mentia sea de despliegue nacional, el país
         * es del sistema. El día que haya un tenant en otro país esto se mueve
         * a su ficha, y esta línea es la única que cambia.
         */
        $pais = (string) config('mentia.pais_norma', 'MX');

        $nacional = $candidatos->first(
            static fn (Baremo $baremo): bool => $baremo->organizacion_id === null
                && $baremo->poblacion?->pais === $pais
        );

        if ($nacional instanceof Baremo) {
            return $nacional;
        }

        // 3. Global publicado.
        return $candidatos->first(
            static fn (Baremo $baremo): bool => $baremo->organizacion_id === null
        );
    }

    /**
     * La fila del baremo que le toca a este puntaje y a esta persona.
     *
     * La segmentación se filtra ANTES del rango de bruto: un puntaje de 18 en
     * la tabla de 6 años no significa lo mismo que en la de 12, y elegir por
     * bruto primero traería la fila de la edad equivocada.
     */
    public function filaPara(Baremo $baremo, Aplicacion $aplicacion, float $bruto): ?BaremoFila
    {
        $edadMeses = $aplicacion->edad_meses_al_aplicar;
        $sexo = $aplicacion->persona?->sexo_registral;

        return BaremoFila::query()
            ->where('baremo_id', $baremo->id)
            ->where('bruto_min', '<=', $bruto)
            ->where('bruto_max', '>=', $bruto)

            // NULL en la fila = no segmenta por ese eje, así que sirve para
            // cualquiera. Sin el `orWhereNull` una tabla sin segmentar no
            // devolvería nada.
            ->where(function ($consulta) use ($edadMeses): void {
                $consulta->whereNull('edad_min_meses')
                    ->orWhere(function ($rango) use ($edadMeses): void {
                        $rango->where('edad_min_meses', '<=', $edadMeses ?? 0)
                            ->where('edad_max_meses', '>=', $edadMeses ?? 0);
                    });
            })
            ->where(function ($consulta) use ($sexo): void {
                $consulta->whereNull('sexo');

                if ($sexo !== null) {
                    $consulta->orWhere('sexo', $sexo);
                }
            })

            /*
             * Ante empate gana la fila MÁS ESPECÍFICA: la que segmenta por edad
             * vence a la que no. Una tabla suele traer las dos —la general y la
             * de cada tramo— y quedarse con la general desperdiciaría la norma
             * fina que alguien se tomó el trabajo de cargar.
             */
            ->orderByRaw('CASE WHEN edad_min_meses IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN sexo IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}
