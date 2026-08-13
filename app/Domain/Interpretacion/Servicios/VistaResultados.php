<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Excepciones\ResultadoNoDisponible;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Interpretacion\Modelos\ResultadoInterpretacion;
use App\Domain\Interpretacion\Modelos\ValidezDetalle;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;

/**
 * Los resultados de una aplicación, filtrados por quién mira.
 *
 * DOS REGLAS QUE GOBIERNAN TODO ESTO:
 *
 * 1. **La audiencia se DERIVA del rol; jamás se pide por parámetro** (Doc 06
 *    §1). Si el evaluado pudiera pedir la audiencia `profesional`, el texto
 *    cuidado que se escribió para él dejaría de servir para nada: bastaría
 *    cambiar un parámetro en la URL para leer la versión técnica de su propio
 *    tamizaje de depresión.
 * 2. **Los números no son para todos.** Sin `resultados.ver_detalle` salen las
 *    interpretaciones y no los puntajes. Un percentil sin nadie que lo explique
 *    es una cifra que la persona va a interpretar sola, y casi siempre mal.
 */
class VistaResultados
{
    public function __construct(private readonly AccesoService $acceso) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ResultadoNoDisponible
     */
    public function para(Persona $actor, Aplicacion $aplicacion): array
    {
        if ($aplicacion->persona_id === null) {
            /*
             * Anónima: no hay resultado individual que entregar, y buscarlo por
             * persona sería justo lo que el anonimato impide. El agregado se
             * pide por asignación.
             */
            throw ResultadoNoDisponible::porSerAnonima();
        }

        $sujeto = $aplicacion->persona;

        $decision = $this->acceso->autorizar(
            actor: $actor,
            accion: 'resultados.ver_resumen',
            sujeto: $sujeto,
            recurso: $aplicacion,
        );

        if (! $decision->permitido) {
            throw ResultadoNoDisponible::porFaltaDeAcceso($decision->motivo);
        }

        $audiencia = $this->audienciaDe($actor, $sujeto);
        $conDetalle = $this->puedeVerDetalle($actor, $sujeto, $aplicacion);

        $salida = [
            'aplicacion_uuid' => $aplicacion->uuid,
            'instrumento' => $aplicacion->version->instrumento->nombre,
            'fecha' => ($aplicacion->finalizada_en ?? $aplicacion->iniciada_en)->toDateString(),
            'audiencia' => $audiencia,
            'validez' => $aplicacion->validez,
            'interpretaciones' => $this->interpretaciones($aplicacion, $audiencia),
        ];

        if (! $conDetalle) {
            return $salida;
        }

        $salida['escalas'] = $this->escalas($aplicacion);

        /*
         * El detalle de validez sólo va a la audiencia profesional. Decirle a
         * quien contestó que su protocolo salió "dudoso por patrón repetido" es
         * acusarlo de no haber leído, con un algoritmo por toda evidencia.
         */
        if ($audiencia === 'profesional') {
            $salida['validez_detalle'] = ValidezDetalle::query()
                ->where('aplicacion_id', $aplicacion->id)
                ->get()
                ->map(static fn (ValidezDetalle $detalle): array => [
                    'verificacion' => $detalle->verificacion,
                    'resultado' => $detalle->resultado,
                    'detalle' => $detalle->detalle,
                ])->values()->all();
        }

        return $salida;
    }

    /**
     * De quién es la audiencia.
     *
     * El titular menor de edad recibe la versión INFANTIL, no la de adulto: un
     * texto escrito para un adulto sobre los resultados de un niño de ocho años
     * no lo entiende ni le sirve.
     */
    public function audienciaDe(Persona $actor, Persona $sujeto): string
    {
        if ($actor->id !== $sujeto->id) {
            $esTutor = Tutoria::query()
                ->vigentes()
                ->where('tutor_persona_id', $actor->id)
                ->where('menor_persona_id', $sujeto->id)
                ->exists();

            return $esTutor ? 'tutor' : 'profesional';
        }

        // Doce años: antes de esa edad el texto de adulto no lo entiende ni le
        // sirve, y a partir de ahí el infantil se lee como que lo tratan de
        // niño, que es la forma más rápida de perder a un adolescente.
        return $sujeto->edadEnMeses() < 12 * 12 ? 'infantil' : 'evaluado_adulto';
    }

    private function puedeVerDetalle(Persona $actor, Persona $sujeto, Aplicacion $aplicacion): bool
    {
        return $this->acceso->autorizar(
            actor: $actor,
            accion: 'resultados.ver_detalle',
            sujeto: $sujeto,
            recurso: $aplicacion,
        )->permitido;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function escalas(Aplicacion $aplicacion): array
    {
        $resultados = ResultadoEscala::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get();

        $claves = Escala::query()
            ->whereIn('id', $resultados->pluck('escala_id')->all())
            ->get()
            ->keyBy('id');

        return $resultados->map(function (ResultadoEscala $resultado) use ($claves): array {
            $escala = $claves->get($resultado->escala_id);

            return [
                'escala' => $escala?->clave,
                'nombre' => $escala?->nombre,
                'bruto' => $resultado->puntaje_bruto,
                'tipo_norma' => $resultado->tipo_norma,
                'normalizado' => $resultado->valor_normalizado,
                'etiqueta' => $resultado->etiqueta_norma,

                /*
                 * `sin_norma` viaja siempre que hay detalle. Un bruto sin
                 * baremo mostrado junto a otros normalizados se lee como si
                 * significara lo mismo, y no significa nada.
                 */
                'sin_norma' => $resultado->sin_norma,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interpretaciones(Aplicacion $aplicacion, string $audiencia): array
    {
        return ResultadoInterpretacion::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->paraAudiencia($audiencia)
            ->get()
            ->map(static fn (ResultadoInterpretacion $texto): array => [
                'texto' => $texto->texto_resuelto,
                'bandera' => $texto->bandera,
            ])->values()->all();
    }
}
