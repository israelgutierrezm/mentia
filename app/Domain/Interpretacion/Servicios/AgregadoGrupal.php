<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Interpretacion\Excepciones\ReporteNoGenerable;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use Illuminate\Support\Collection;

/**
 * El agregado de una asignación: distribuciones y semáforos.
 *
 * LA REGLA QUE GOBIERNA TODO ESTO ES EL TAMAÑO MÍNIMO. Un reporte grupal de un
 * grupo de tres personas no es un agregado: es la lista de esas tres personas
 * escrita de otra forma. En una NOM-035 anónima eso deshace el anonimato —el
 * jefe sabe quiénes son los tres— y con él la única razón por la que la gente
 * contestó con la verdad.
 *
 * El mínimo es configurable hacia arriba y no baja de 5.
 */
class AgregadoGrupal
{
    public function minimoDeGrupo(): int
    {
        return max(5, (int) config('mentia.reportes.minimo_grupo', 5));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReporteNoGenerable
     */
    public function para(Asignacion $asignacion): array
    {
        $aplicaciones = Aplicacion::query()
            ->withoutGlobalScopes()
            ->whereHas('destinatario', fn ($consulta) => $consulta
                ->where('asignacion_id', $asignacion->id))
            ->where('estado', 'completada')
            ->get();

        if ($aplicaciones->count() < $this->minimoDeGrupo()) {
            throw ReporteNoGenerable::porGrupoDemasiadoChico(
                $aplicaciones->count(),
                $this->minimoDeGrupo(),
            );
        }

        $resultados = ResultadoEscala::query()
            ->whereIn('aplicacion_id', $aplicaciones->pluck('id')->all())
            ->get();

        $escalas = Escala::query()
            ->whereIn('id', $resultados->pluck('escala_id')->unique()->all())
            ->orderBy('orden')
            ->get();

        return [
            'folio' => $asignacion->folio,
            'contestadas' => $aplicaciones->count(),
            'anonima' => $asignacion->es_anonima,
            'escalas' => $escalas->map(
                fn (Escala $escala): array => $this->deUnaEscala($escala, $resultados)
            )->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, ResultadoEscala>  $todos
     * @return array<string, mixed>
     */
    private function deUnaEscala(Escala $escala, Collection $todos): array
    {
        $delaEscala = $todos->where('escala_id', $escala->id);

        $etiquetas = [];

        foreach ($delaEscala as $resultado) {
            /*
             * El semáforo agregado cuenta ETIQUETAS, no promedia valores.
             * Promediar categorías —«medio» y «muy alto» dan «alto»— produce un
             * número que no describe a nadie del grupo y esconde justamente a
             * quien está peor.
             */
            $clave = $resultado->etiqueta_norma ?? ($resultado->sin_norma ? 'sin_norma' : 'sin_etiqueta');
            $etiquetas[$clave] = ($etiquetas[$clave] ?? 0) + 1;
        }

        arsort($etiquetas);

        $brutos = $delaEscala->pluck('puntaje_bruto')->map(
            static fn ($valor): float => (float) $valor
        )->sort()->values();

        return [
            'escala' => $escala->clave,
            'nombre' => $escala->nombre,
            'n' => $delaEscala->count(),
            'media' => $brutos->isEmpty() ? null : round($brutos->avg(), 2),
            'mediana' => $this->mediana($brutos->all()),
            'minimo' => $brutos->first(),
            'maximo' => $brutos->last(),
            'distribucion' => $etiquetas,
        ];
    }

    /**
     * @param  list<float>  $ordenados
     */
    private function mediana(array $ordenados): ?float
    {
        $cuantos = count($ordenados);

        if ($cuantos === 0) {
            return null;
        }

        $medio = intdiv($cuantos, 2);

        return $cuantos % 2 === 1
            ? $ordenados[$medio]
            : round(($ordenados[$medio - 1] + $ordenados[$medio]) / 2, 2);
    }
}
