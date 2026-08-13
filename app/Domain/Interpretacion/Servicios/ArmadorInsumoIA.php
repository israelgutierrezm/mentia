<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use Illuminate\Support\Collection;

/**
 * Arma lo que se le manda a la IA, PSEUDONIMIZADO.
 *
 * El Doc 05 §6 fija el contrato y esta clase lo hace cumplir:
 *
 * - Entra: escalas, normas, textos de interpretación YA RESUELTOS, banderas y
 *   validez. Nada más.
 * - NO entra: nombre, CURP, fecha de nacimiento, correo, teléfono, la
 *   organización, ni las respuestas crudas. Una respuesta abierta puede
 *   contener cualquier cosa que la persona haya querido escribir, y en un
 *   tamizaje clínico eso incluye lo más delicado de su expediente.
 *
 * La edad sí entra, en AÑOS y no en fecha: un reporte que no sabe si habla de
 * alguien de siete o de cuarenta años no sirve, y el año de edad no identifica
 * a nadie.
 *
 * Que la pseudonimización viva aquí y no en quien llama es el punto: si cada
 * llamador armara su propio insumo, tarde o temprano uno metería el nombre
 * "para que el reporte quede mejor".
 */
class ArmadorInsumoIA
{
    /**
     * @param  list<Aplicacion>  $aplicaciones
     * @return array<string, mixed>
     */
    public function para(array $aplicaciones): array
    {
        $instrumentos = [];

        foreach ($aplicaciones as $aplicacion) {
            $instrumentos[] = $this->deUnaAplicacion($aplicacion);
        }

        $primera = $aplicaciones[0] ?? null;

        return [
            'edad_anios' => $primera?->edad_meses_al_aplicar === null
                ? null
                : intdiv($primera->edad_meses_al_aplicar, 12),

            'instrumentos' => $instrumentos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deUnaAplicacion(Aplicacion $aplicacion): array
    {
        $aplicacion->loadMissing('version.instrumento');

        $resultados = ResultadoEscala::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get();

        $escalas = Escala::query()
            ->whereIn('id', $resultados->pluck('escala_id')->all())
            ->get()
            ->keyBy('id');

        return [
            'instrumento' => $aplicacion->version->instrumento->nombre,
            'fecha' => ($aplicacion->finalizada_en ?? $aplicacion->iniciada_en)->toDateString(),
            'validez' => $aplicacion->validez,

            'escalas' => $resultados->map(
                static fn (ResultadoEscala $resultado): array => [
                    'escala' => $escalas->get($resultado->escala_id)?->nombre,
                    'bruto' => $resultado->puntaje_bruto,
                    'tipo_norma' => $resultado->tipo_norma,
                    'normalizado' => $resultado->valor_normalizado,
                    'etiqueta' => $resultado->etiqueta_norma,
                    'sin_norma' => $resultado->sin_norma,
                ]
            )->values()->all(),

            /*
             * SÓLO la audiencia profesional. El texto del evaluado está
             * escrito para tranquilizar y acompañar; integrarlo en un reporte
             * técnico produciría un documento que suena a folleto, y el
             * profesional necesita lo otro.
             */
            'interpretaciones' => $this->interpretaciones($aplicacion),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interpretaciones(Aplicacion $aplicacion): array
    {
        /** @var Collection<int, ResultadoInterpretacion> $textos */
        $textos = ResultadoInterpretacion::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->paraAudiencia('profesional')
            ->get();

        return $textos->map(
            static fn (ResultadoInterpretacion $texto): array => [
                'texto' => $texto->texto_resuelto,
                'bandera' => $texto->bandera,
            ]
        )->values()->all();
    }

    /**
     * El hash del insumo.
     *
     * Sirve para probar que dos borradores salieron de los mismos datos y para
     * detectar que alguien recalificó en medio, sin guardar una segunda copia
     * del material clínico fuera de su tabla.
     *
     * @param  array<string, mixed>  $insumo
     */
    public function hashDe(array $insumo): string
    {
        return hash('sha256', (string) json_encode($insumo, JSON_THROW_ON_ERROR));
    }
}
