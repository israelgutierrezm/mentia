<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Validez;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Etapa 1 — `patron_repetido` (Doc 05 §2): straight-lining.
 *
 * La misma respuesta N veces seguidas en un likert. Es la firma de quien
 * bajó por la columna sin leer, y produce un perfil perfectamente plano que
 * cualquier interpretación lee como "sin problemas".
 *
 * Se cuenta sobre reactivos CONSECUTIVOS EN ORDEN DE PRESENTACIÓN, no sobre el
 * orden en que llegaron las respuestas: quien contesta, corrige y vuelve
 * produce un orden de llegada revuelto que no dice nada del patrón.
 *
 * Parámetros: `consecutivas_max` (por omisión 8) y `al_exceder`.
 */
class PatronRepetido implements EstrategiaCalificacion
{
    public static function clave(): string
    {
        return 'patron_repetido';
    }

    public static function etapa(): string
    {
        return 'validez';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $maximo = max(2, (int) ($parametros['consecutivas_max'] ?? 8));

        $racha = 0;
        $rachaMayor = 0;
        $anterior = null;

        foreach ($contexto->reactivos as $reactivo) {
            $respuesta = $contexto->respuestasDe($reactivo->id)->first();

            if ($respuesta === null || $respuesta->valor_numerico === null) {
                // Un hueco corta la racha: dos tramos idénticos separados por
                // un reactivo sin contestar no son la misma bajada de columna.
                $racha = 0;
                $anterior = null;

                continue;
            }

            $valor = (float) $respuesta->valor_numerico;

            $racha = ($anterior !== null && $anterior === $valor) ? $racha + 1 : 1;
            $anterior = $valor;
            $rachaMayor = max($rachaMayor, $racha);
        }

        if ($rachaMayor < $maximo) {
            $contexto->anotarValidez(
                'patron_repetido',
                'paso',
                sprintf('Racha máxima de %d respuestas idénticas seguidas.', $rachaMayor),
            );

            return;
        }

        $resultado = ($parametros['al_exceder'] ?? 'dudosa') === 'invalida'
            ? 'fallo'
            : 'advertencia';

        $contexto->anotarValidez(
            'patron_repetido',
            $resultado,
            sprintf(
                '%d respuestas idénticas seguidas, en el límite de %d o por encima.',
                $rachaMayor,
                $maximo,
            ),
        );
    }
}
