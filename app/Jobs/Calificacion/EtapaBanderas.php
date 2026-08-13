<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 6 — banderas (Doc 05 §2).
 *
 * Copia la bandera de las reglas que se dispararon a `resultados_normalizados`,
 * que es lo que hace que la gráfica longitudinal pueda pintar en rojo el punto
 * de hace tres años sin volver a interpretar nada.
 *
 * Ante varias banderas para la misma escala gana LA MÁS GRAVE. Quedarse con la
 * última en llegar haría que una regla verde de prioridad baja tapara una roja,
 * y el rojo es justamente lo que nadie puede perderse.
 *
 * Los `protocolo_reglas` —asignar automáticamente la segunda etapa de un
 * M-CHAT, notificar a un rol— son de la Fase 8. Van aquí cuando lleguen.
 */
class EtapaBanderas extends EtapaDelPipeline
{
    /** De menos a más grave. */
    private const ORDEN_GRAVEDAD = ['verde' => 0, 'amarillo' => 1, 'rojo' => 2];

    protected function etapa(): string
    {
        return 'banderas';
    }

    protected function procesar(ContextoCalificacion $contexto): void
    {
        $aplicacion = $contexto->aplicacion;

        if ($aplicacion->persona_id === null) {
            // Anónima: no hay serie que marcar.
            return;
        }

        $porEscala = $this->banderaPorEscala($contexto);

        if ($porEscala === []) {
            return;
        }

        DB::transaction(function () use ($aplicacion, $porEscala): void {
            foreach ($porEscala as $constructo => $bandera) {
                ResultadoNormalizado::query()
                    ->where('aplicacion_id', $aplicacion->id)
                    ->where('constructo', $constructo)
                    ->update(['bandera' => $bandera]);
            }
        });
    }

    /**
     * @return array<string, string> Clave de escala → bandera.
     */
    private function banderaPorEscala(ContextoCalificacion $contexto): array
    {
        $interpretaciones = ResultadoInterpretacion::query()
            ->where('aplicacion_id', $contexto->aplicacion->id)
            ->whereNotNull('bandera')
            ->with('regla')
            ->get();

        $porEscala = [];

        foreach ($interpretaciones as $interpretacion) {
            $escalaId = $interpretacion->regla?->escala_id;

            if ($escalaId === null) {
                continue;
            }

            $escala = $contexto->escalas->firstWhere('id', $escalaId);

            if ($escala === null) {
                continue;
            }

            $actual = $porEscala[$escala->clave] ?? null;
            $nueva = (string) $interpretacion->bandera;

            if ($actual === null || $this->gravedad($nueva) > $this->gravedad($actual)) {
                $porEscala[$escala->clave] = $nueva;
            }
        }

        return $porEscala;
    }

    private function gravedad(string $bandera): int
    {
        return self::ORDEN_GRAVEDAD[$bandera] ?? 0;
    }
}
