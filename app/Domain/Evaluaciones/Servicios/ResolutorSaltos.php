<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\ReglaSalto;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use Illuminate\Support\Collection;

/**
 * Resuelve las reglas de salto. SIEMPRE en el servidor.
 *
 * Mandarle el árbol de saltos al cliente le entregaría el mapa completo del
 * instrumento —qué preguntas existen y bajo qué condiciones aparecen—, que es
 * justo lo que la entrega parcelada evita (Doc 02 §2).
 *
 * Un salto no BORRA reactivos: los oculta para esta aplicación concreta. Si la
 * persona cambia la respuesta que disparó el salto, lo saltado vuelve a
 * aparecer, y eso sólo funciona si se recalcula en cada entrega.
 */
class ResolutorSaltos
{
    /**
     * De los reactivos del bloque, los que esta persona debe ver ahora.
     *
     * @param  Collection<int, Reactivo>  $reactivos
     * @return Collection<int, Reactivo>
     */
    public function filtrarVisibles(Aplicacion $aplicacion, Collection $reactivos): Collection
    {
        if ($reactivos->isEmpty()) {
            return $reactivos;
        }

        $reglas = ReglaSalto::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->get();

        if ($reglas->isEmpty()) {
            return $reactivos;
        }

        $respuestas = Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get()
            ->keyBy('reactivo_id');

        $ocultos = $this->reactivosOcultos($reglas, $respuestas, $reactivos);

        return $reactivos->reject(
            fn (Reactivo $reactivo): bool => in_array($reactivo->id, $ocultos, true)
        )->values();
    }

    /**
     * ¿A dónde manda esta respuesta?
     *
     * Lo usa el motor para decidir el siguiente reactivo tras un lote.
     *
     * @return array{tipo: string, destino_id: int|null}|null
     */
    public function destinoTras(Aplicacion $aplicacion, Respuesta $respuesta): ?array
    {
        $reglas = ReglaSalto::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->where('reactivo_origen_id', $respuesta->reactivo_id)
            ->get();

        foreach ($reglas as $regla) {
            if ($this->seCumple($regla, $respuesta)) {
                return ['tipo' => $regla->destino_tipo, 'destino_id' => $regla->destino_id];
            }
        }

        return null;
    }

    /**
     * Los ids que quedan ocultos por un salto ya disparado.
     *
     * Un salto a un reactivo posterior oculta TODO lo que hay entre el origen
     * y el destino. Es la semántica de "salta a la pregunta 12": las de en
     * medio no se contestan.
     *
     * @param  Collection<int, ReglaSalto>  $reglas
     * @param  Collection<int, Respuesta>  $respuestas
     * @param  Collection<int, Reactivo>  $reactivos
     * @return list<int>
     */
    private function reactivosOcultos(
        Collection $reglas,
        Collection $respuestas,
        Collection $reactivos,
    ): array {
        $porOrden = $reactivos->sortBy('orden')->values();
        $ocultos = [];

        foreach ($reglas as $regla) {
            $respuesta = $respuestas->get($regla->reactivo_origen_id);

            if ($respuesta === null || ! $this->seCumple($regla, $respuesta)) {
                continue;
            }

            if ($regla->destino_tipo === 'fin') {
                // Todo lo posterior al origen se oculta.
                $ocultos = [...$ocultos, ...$this->posterioresA($porOrden, $regla->reactivo_origen_id)];

                continue;
            }

            if ($regla->destino_tipo !== 'reactivo' || $regla->destino_id === null) {
                // Saltar a otro bloque no oculta nada DENTRO de este.
                continue;
            }

            $ocultos = [
                ...$ocultos,
                ...$this->entre($porOrden, $regla->reactivo_origen_id, (int) $regla->destino_id),
            ];
        }

        return array_values(array_unique($ocultos));
    }

    /**
     * @param  Collection<int, Reactivo>  $porOrden
     * @return list<int>
     */
    private function entre(Collection $porOrden, int $origenId, int $destinoId): array
    {
        $posOrigen = $porOrden->search(fn (Reactivo $r): bool => $r->id === $origenId);
        $posDestino = $porOrden->search(fn (Reactivo $r): bool => $r->id === $destinoId);

        if ($posOrigen === false || $posDestino === false || $posDestino <= $posOrigen) {
            return [];
        }

        return $porOrden->slice($posOrigen + 1, $posDestino - $posOrigen - 1)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  Collection<int, Reactivo>  $porOrden
     * @return list<int>
     */
    private function posterioresA(Collection $porOrden, int $origenId): array
    {
        $posOrigen = $porOrden->search(fn (Reactivo $r): bool => $r->id === $origenId);

        if ($posOrigen === false) {
            return [];
        }

        return $porOrden->slice($posOrigen + 1)->pluck('id')->all();
    }

    private function seCumple(ReglaSalto $regla, Respuesta $respuesta): bool
    {
        if ($regla->opcion_id !== null) {
            return $respuesta->opcion_id === $regla->opcion_id;
        }

        return match ($regla->condicion) {
            'respondida' => true,
            'igual' => $this->comparar($respuesta, $regla->valor, '='),
            'mayor' => $this->comparar($respuesta, $regla->valor, '>'),
            'menor' => $this->comparar($respuesta, $regla->valor, '<'),
            default => false,
        };
    }

    private function comparar(Respuesta $respuesta, ?string $valor, string $operador): bool
    {
        if ($valor === null || $respuesta->valor_numerico === null) {
            return false;
        }

        $izquierda = (float) $respuesta->valor_numerico;
        $derecha = (float) $valor;

        return match ($operador) {
            '>' => $izquierda > $derecha,
            '<' => $izquierda < $derecha,
            default => abs($izquierda - $derecha) < 0.0001,
        };
    }
}
