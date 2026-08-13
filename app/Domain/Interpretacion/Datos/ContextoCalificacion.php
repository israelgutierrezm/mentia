<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Datos;

use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que una etapa necesita saber y donde deja lo que produce.
 *
 * Se RECONSTRUYE desde la base al empezar cada etapa, no se pasa serializado
 * entre jobs. Dos razones y las dos pesan:
 *
 * 1. Un job encolado con el contexto dentro dejaría respuestas de un tamizaje
 *    clínico escritas en la tabla `jobs` y en los paneles de Horizon.
 * 2. Cada etapa persiste su salida (Doc 05 §1.2), así que la base ya es la
 *    fuente. Reconstruir desde ahí es lo que hace que se pueda recalificar
 *    desde la etapa cuatro sin volver a sumar.
 */
class ContextoCalificacion
{
    /** @var Collection<int, Respuesta> */
    public Collection $respuestas;

    /** @var Collection<int, Reactivo> */
    public Collection $reactivos;

    /** @var Collection<int, Escala> */
    public Collection $escalas;

    /**
     * Puntajes por escala, indexados por `escala_id`. Es lo que la etapa de
     * brutos llena y lo que las siguientes leen.
     *
     * @var array<int, float>
     */
    public array $brutos = [];

    /** @var list<array{verificacion: string, resultado: string, detalle: string}> */
    public array $validez = [];

    /**
     * Etiquetas que un algoritmo especial pone sobre una escala, por clave de
     * escala: `riesgo_medio`, `alto`, `zona_III`.
     *
     * Es la salida de la etapa 3. Un algoritmo con cortes oficiales —la
     * NOM-035, el AUDIT— no produce un percentil: produce una CATEGORÍA, y esa
     * categoría manda sobre cualquier baremo. Guardarla aquí es lo que evita
     * que las estrategias se llamen entre ellas.
     *
     * @var array<string, string>
     */
    public array $etiquetas = [];

    private function __construct(public readonly Aplicacion $aplicacion)
    {
        $this->respuestas = collect();
        $this->reactivos = collect();
        $this->escalas = collect();
    }

    public static function para(Aplicacion $aplicacion): self
    {
        $contexto = new self($aplicacion);

        $aplicacion->loadMissing('version.instrumento');

        $contexto->respuestas = Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->with('reactivo')
            ->get();

        $contexto->reactivos = Reactivo::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->orderBy('orden')
            ->get();

        $contexto->escalas = Escala::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->orderBy('orden')
            ->get();

        // Los brutos ya calculados, si esta no es la primera etapa.
        foreach (ResultadoEscala::query()->where('aplicacion_id', $aplicacion->id)->get() as $resultado) {
            $contexto->brutos[$resultado->escala_id] = $resultado->puntaje_bruto;
        }

        return $contexto;
    }

    public function escalaPorClave(string $clave): ?Escala
    {
        return $this->escalas->firstWhere('clave', $clave);
    }

    public function bruto(string $claveEscala): ?float
    {
        $escala = $this->escalaPorClave($claveEscala);

        if ($escala === null) {
            return null;
        }

        return $this->brutos[$escala->id] ?? null;
    }

    public function anotarBruto(int $escalaId, float $valor): void
    {
        $this->brutos[$escalaId] = $valor;
    }

    /**
     * ¿Se contestó algo de esta escala?
     *
     * NO es lo mismo que "su bruto es cero". La etapa de brutos deja en cero
     * toda escala que ninguna respuesta tocó —para que no desaparezca de los
     * resultados—, y eso hace que un cero de verdad y un bloque sin contestar
     * se vean igual.
     *
     * En un M-CHAT esa diferencia decide si una familia va a evaluación
     * especializada: la entrevista de seguimiento con puntaje 0 baja el riesgo,
     * y la entrevista sin contestar no baja nada porque no ocurrió.
     */
    public function tieneRespuestasPara(string $claveEscala): bool
    {
        $escala = $this->escalaPorClave($claveEscala);

        if ($escala === null) {
            return false;
        }

        $reactivosDeLaEscala = ClaveCalificacion::query()
            ->where('version_instrumento_id', $this->aplicacion->version_instrumento_id)
            ->where('escala_id', $escala->id)
            ->pluck('reactivo_id')
            ->unique()
            ->all();

        if ($reactivosDeLaEscala === []) {
            return false;
        }

        return $this->respuestas
            ->whereIn('reactivo_id', $reactivosDeLaEscala)
            ->isNotEmpty();
    }

    public function anotarValidez(string $verificacion, string $resultado, string $detalle): void
    {
        $this->validez[] = [
            'verificacion' => $verificacion,
            'resultado' => $resultado,
            'detalle' => $detalle,
        ];
    }

    /**
     * Las respuestas de un reactivo. Varias sólo en ranking e ipsativos.
     *
     * @return Collection<int, Respuesta>
     */
    public function respuestasDe(int $reactivoId): Collection
    {
        return $this->respuestas->where('reactivo_id', $reactivoId)->values();
    }

    /**
     * Reactivos que la persona debía contestar: los obligatorios que no quedaron
     * ocultos por un salto.
     *
     * @return Collection<int, Reactivo>
     */
    public function reactivosEsperados(): Collection
    {
        return $this->reactivos->where('obligatorio', true)->values();
    }

    public function fechaDeAplicacion(): Carbon
    {
        return $this->aplicacion->finalizada_en ?? $this->aplicacion->iniciada_en;
    }
}
