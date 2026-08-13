<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * `mchat_dos_etapas` (Doc 05 §2).
 *
 * El M-CHAT-R/F no es un tamizaje de un paso. Su puntaje inicial abre tres
 * caminos distintos, y el de en medio no es un resultado sino una INSTRUCCIÓN:
 *
 * - 0–2: riesgo bajo. Se cierra.
 * - 3–7: riesgo medio. **No se concluye nada todavía**: hay que aplicar la
 *   entrevista de seguimiento y recalificar con ella. La mitad de los que caen
 *   aquí bajan a riesgo bajo tras la entrevista, y tratar el 3 inicial como
 *   resultado final manda a evaluación especializada a familias que no la
 *   necesitan.
 * - 8–20: riesgo alto. Se deriva sin entrevista.
 *
 * Esta estrategia decide en cuál de los tres se está y, en el de en medio,
 * distingue si la entrevista ya se contestó. Quién dispara la entrevista es
 * asunto de `protocolo_reglas` (Fase 8): aquí sólo se deja dicho.
 *
 * Parámetros: `escala` (la del total), `escala_seguimiento` (la de la
 * entrevista, opcional) y los cortes `medio_desde` / `alto_desde`.
 */
class MchatDosEtapas implements EstrategiaCalificacion
{
    public static function clave(): string
    {
        return 'mchat_dos_etapas';
    }

    public static function etapa(): string
    {
        return 'algoritmos';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claveEscala = $parametros['escala'] ?? null;

        if ($claveEscala === null) {
            return;
        }

        $bruto = $contexto->bruto($claveEscala);

        if ($bruto === null) {
            return;
        }

        $medioDesde = (float) ($parametros['medio_desde'] ?? 3);
        $altoDesde = (float) ($parametros['alto_desde'] ?? 8);

        if ($bruto >= $altoDesde) {
            $contexto->etiquetas[$claveEscala] = 'riesgo_alto';

            return;
        }

        if ($bruto < $medioDesde) {
            $contexto->etiquetas[$claveEscala] = 'riesgo_bajo';

            return;
        }

        $contexto->etiquetas[$claveEscala] = $this->conSeguimiento($contexto, $parametros, $altoDesde);
    }

    /**
     * @param  array<string, string>  $parametros
     */
    private function conSeguimiento(
        ContextoCalificacion $contexto,
        array $parametros,
        float $altoDesde,
    ): string {
        $claveSeguimiento = $parametros['escala_seguimiento'] ?? null;

        if ($claveSeguimiento === null) {
            return 'riesgo_medio_sin_entrevista';
        }

        /*
         * Se pregunta si la entrevista SE CONTESTÓ, no si su puntaje es cero.
         * La etapa de brutos deja en cero toda escala que nadie tocó, así que
         * mirar el puntaje confundiría "entrevista con resultado 0" —que baja
         * el riesgo— con "entrevista sin aplicar", que no baja nada porque no
         * ocurrió. Confundirlas cerraría en riesgo bajo un tamizaje a medio
         * terminar.
         */
        if (! $contexto->tieneRespuestasPara($claveSeguimiento)) {
            return 'riesgo_medio_pendiente_entrevista';
        }

        $seguimiento = $contexto->bruto($claveSeguimiento) ?? 0.0;

        /*
         * RECALIFICACIÓN: manda el puntaje de la entrevista. Es el punto entero
         * del instrumento de dos etapas —la segunda existe para corregir los
         * falsos positivos de la primera— y quedarse con el inicial haría que
         * la entrevista no sirviera de nada.
         */
        $recalificacion = $seguimiento >= 2 ? 'riesgo_alto' : 'riesgo_bajo';

        if ($seguimiento >= $altoDesde) {
            $recalificacion = 'riesgo_alto';
        }

        return $recalificacion;
    }
}
