<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\FormulaDerivada;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Interpretacion\Servicios\EvaluadorFormulas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 2 — puntajes brutos y fórmulas derivadas (Doc 05 §2).
 *
 * Corre las estrategias configuradas y luego evalúa las fórmulas en su
 * `orden_evaluacion`. El orden importa de verdad: el T del Cleaver es M − L, y
 * calcularlo antes de tener M y L da cero sin quejarse.
 */
class EtapaBrutos extends EtapaDelPipeline
{
    protected function etapa(): string
    {
        return 'brutos';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        foreach ($this->estrategiasConfiguradas($contexto) as $configurada) {
            $configurada['estrategia']->ejecutar($contexto, $configurada['parametros']);
        }

        $this->evaluarFormulas($contexto);
        $this->persistir($contexto);
    }

    private function evaluarFormulas(ContextoCalificacion $contexto): void
    {
        $formulas = FormulaDerivada::query()
            ->where('version_instrumento_id', $contexto->aplicacion->version_instrumento_id)
            ->orderBy('orden_evaluacion')
            ->get();

        if ($formulas->isEmpty()) {
            return;
        }

        $evaluador = app(EvaluadorFormulas::class);

        foreach ($formulas as $formula) {
            /*
             * Los valores se rearman en CADA vuelta, no una sola vez al
             * principio: una fórmula puede citar la escala que produjo la
             * anterior, y con un mapa congelado leería el valor de antes.
             */
            $valores = [];

            foreach ($contexto->escalas as $escala) {
                $valores[$escala->clave] = $contexto->brutos[$escala->id] ?? 0.0;
            }

            $contexto->anotarBruto(
                $formula->escala_destino_id,
                $evaluador->evaluar($formula->expresion, $valores),
            );
        }
    }

    private function persistir(ContextoCalificacion $contexto): void
    {
        $ahora = Carbon::now();

        DB::transaction(function () use ($contexto, $ahora): void {
            foreach ($contexto->brutos as $escalaId => $bruto) {
                // Las escalas de otra versión no tienen nada que hacer aquí:
                // una configuración mal cargada podría citar una ajena.
                $escala = $contexto->escalas->firstWhere('id', $escalaId);

                if (! $escala instanceof Escala) {
                    continue;
                }

                ResultadoEscala::query()->updateOrCreate(
                    [
                        'aplicacion_id' => $contexto->aplicacion->id,
                        'escala_id' => $escalaId,
                    ],
                    [
                        'puntaje_bruto' => round($bruto, 3),
                        'calculado_en' => $ahora,

                        /*
                         * LA NORMALIZACIÓN SE LIMPIA. Un bruto recalculado
                         * invalida el percentil que se sacó del anterior, y la
                         * etapa 4 se salta a propósito lo que ya trae
                         * `tipo_norma` —para no pisar los cortes oficiales que
                         * puso la etapa 3—. Sin este borrado, una
                         * recalificación dejaría el bruto nuevo con la norma
                         * vieja pegada al lado, que es la peor de las dos
                         * mentiras posibles.
                         */
                        'baremo_id' => null,
                        'valor_normalizado' => null,
                        'tipo_norma' => null,
                        'etiqueta_norma' => null,
                        'sin_norma' => false,
                    ],
                );
            }
        });
    }
}
