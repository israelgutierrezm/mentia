<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\PerfilTipo;
use App\Domain\Catalogo\Modelos\PerfilTipoCondicion;
use App\Domain\Catalogo\Modelos\ReglaInterpretacion;
use App\Domain\Catalogo\Modelos\ReglaInterpretacionCondicion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use Illuminate\Support\Collection;

/**
 * Etapa 5 — interpretación (Doc 05 §2).
 *
 * Resuelve las reglas en orden de `prioridad` y produce el TEXTO por audiencia.
 * Los tres tipos del Doc 05 y ninguno más:
 *
 * - `rango_escala`: una condición sobre una escala.
 * - `combinacion`: varias condiciones con AND/OR.
 * - `perfil_tipo`: pertenencia a una tipología (Cleaver, DISC, código RIASEC).
 *
 * Lo que NO hace: inventar texto. Todo lo que sale de aquí estaba escrito en el
 * catálogo por alguien que responde por ello. El sistema sugiere; quien
 * diagnostica es el profesional (principio P6).
 */
class ResolutorInterpretaciones
{
    public function __construct(private readonly EvaluadorCondiciones $condiciones) {}

    /**
     * @return list<array{regla_interpretacion_id: int|null, perfil_tipo_id: int|null, audiencia: string, texto_resuelto: string, bandera: string|null, orden: int}>
     */
    public function resolver(ContextoCalificacion $contexto): array
    {
        $resultados = ResultadoEscala::query()
            ->where('aplicacion_id', $contexto->aplicacion->id)
            ->get();

        $salida = [];
        $orden = 0;

        foreach ($this->reglasVigentes($contexto) as $regla) {
            if (! $this->aplica($regla, $resultados)) {
                continue;
            }

            $salida[] = [
                'regla_interpretacion_id' => $regla->id,
                'perfil_tipo_id' => null,
                'audiencia' => $regla->audiencia,
                'texto_resuelto' => $this->resolverVariables(
                    $this->textoCompleto($regla),
                    $contexto,
                    $resultados,
                    $regla->escala_id,
                ),
                'bandera' => $regla->bandera,
                'orden' => $orden++,
            ];
        }

        foreach ($this->perfilesQueAplican($contexto, $resultados) as $perfil) {
            $salida[] = [
                'regla_interpretacion_id' => null,
                'perfil_tipo_id' => $perfil->id,
                'audiencia' => 'profesional',
                'texto_resuelto' => $this->resolverVariables(
                    $perfil->descripcion_profesional ?? $perfil->nombre,
                    $contexto,
                    $resultados,
                    null,
                ),
                'bandera' => null,
                'orden' => $orden++,
            ];

            $salida[] = [
                'regla_interpretacion_id' => null,
                'perfil_tipo_id' => $perfil->id,
                'audiencia' => 'evaluado_adulto',
                'texto_resuelto' => $this->resolverVariables(
                    $perfil->descripcion_evaluado ?? $perfil->nombre,
                    $contexto,
                    $resultados,
                    null,
                ),
                'bandera' => null,
                'orden' => $orden++,
            ];
        }

        return $salida;
    }

    /**
     * @return Collection<int, ReglaInterpretacion>
     */
    private function reglasVigentes(ContextoCalificacion $contexto): Collection
    {
        return ReglaInterpretacion::query()
            ->where('version_instrumento_id', $contexto->aplicacion->version_instrumento_id)
            ->where('vigente', true)
            ->where('tipo_regla', '!=', 'perfil_tipo')
            ->with('condiciones')
            ->orderBy('prioridad')
            ->get();
    }

    /**
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    private function aplica(ReglaInterpretacion $regla, Collection $resultados): bool
    {
        if ($regla->tipo_regla === 'rango_escala') {
            if ($regla->escala_id === null) {
                return false;
            }

            return $this->condiciones->cumple(
                $resultados,
                $regla->escala_id,
                $regla->tipo_puntaje,
                $regla->operador,
                $regla->valor_min === null ? null : (float) $regla->valor_min,
                $regla->valor_max === null ? null : (float) $regla->valor_max,
            );
        }

        return $this->evaluarCondiciones($regla->condiciones, $resultados);
    }

    /**
     * Evalúa una cadena de condiciones con sus conectores.
     *
     * El conector lo lleva CADA condición y describe cómo se une con la
     * anterior; la primera lo ignora. Se resuelve de izquierda a derecha, sin
     * precedencia de AND sobre OR: la alternativa sería un árbol, y un árbol
     * capturado en una hoja de Excel es un árbol que nadie va a poder revisar.
     * Quien necesite agrupar, parte la regla en dos.
     *
     * @param  Collection<int, ReglaInterpretacionCondicion>  $condiciones
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    private function evaluarCondiciones(Collection $condiciones, Collection $resultados): bool
    {
        if ($condiciones->isEmpty()) {
            return false;
        }

        $acumulado = null;

        foreach ($condiciones as $condicion) {
            $cumple = $this->condiciones->cumple(
                $resultados,
                $condicion->escala_id,
                $condicion->tipo_puntaje,
                $condicion->operador,
                $condicion->valor_min === null ? null : (float) $condicion->valor_min,
                $condicion->valor_max === null ? null : (float) $condicion->valor_max,
            );

            if ($acumulado === null) {
                $acumulado = $cumple;

                continue;
            }

            $acumulado = $condicion->conector === 'OR'
                ? ($acumulado || $cumple)
                : ($acumulado && $cumple);
        }

        return $acumulado ?? false;
    }

    /**
     * Los perfiles tipo que le corresponden a este resultado.
     *
     * Dos formas, y las dos hacen falta:
     *
     * - Con condiciones: se evalúan como cualquier combinación.
     * - Sin condiciones: el `codigo` se compara contra las N escalas MÁS ALTAS,
     *   que es como funcionan el código RIASEC de tres letras y los tipos DISC.
     *   Es config-driven de verdad: agregar el perfil 'RIA' es una fila.
     *
     * @param  Collection<int, ResultadoEscala>  $resultados
     * @return Collection<int, PerfilTipo>
     */
    private function perfilesQueAplican(ContextoCalificacion $contexto, Collection $resultados): Collection
    {
        $perfiles = PerfilTipo::query()
            ->where('version_instrumento_id', $contexto->aplicacion->version_instrumento_id)
            ->with('condiciones')
            ->orderBy('orden')
            ->get();

        return $perfiles->filter(function (PerfilTipo $perfil) use ($contexto, $resultados): bool {
            /** @var Collection<int, PerfilTipoCondicion> $condiciones */
            $condiciones = $perfil->condiciones;

            if ($condiciones->isNotEmpty()) {
                return $this->evaluarCondicionesDePerfil($condiciones, $resultados);
            }

            return $perfil->codigo === $this->codigoDeLasMasAltas(
                $contexto,
                $resultados,
                strlen($perfil->codigo),
            );
        })->values();
    }

    /**
     * @param  Collection<int, PerfilTipoCondicion>  $condiciones
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    private function evaluarCondicionesDePerfil(Collection $condiciones, Collection $resultados): bool
    {
        $acumulado = null;

        foreach ($condiciones as $condicion) {
            $cumple = $this->condiciones->cumple(
                $resultados,
                $condicion->escala_id,
                $condicion->tipo_puntaje,
                $condicion->operador,
                $condicion->valor_min === null ? null : (float) $condicion->valor_min,
                $condicion->valor_max === null ? null : (float) $condicion->valor_max,
            );

            if ($acumulado === null) {
                $acumulado = $cumple;

                continue;
            }

            $acumulado = $condicion->conector === 'OR'
                ? ($acumulado || $cumple)
                : ($acumulado && $cumple);
        }

        return $acumulado ?? false;
    }

    /**
     * El código de las N escalas más altas: 'RIA', 'DI', 'SEC'.
     *
     * Los EMPATES se resuelven por el orden declarado de la escala en el
     * catálogo, no por el id ni por azar. Dos personas con el mismo perfil
     * tienen que recibir el mismo código las dos veces; un desempate que
     * dependa del orden en que la base devolvió las filas produce resultados
     * que cambian al recalificar.
     *
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    private function codigoDeLasMasAltas(
        ContextoCalificacion $contexto,
        Collection $resultados,
        int $cuantas,
    ): string {
        if ($cuantas < 1) {
            return '';
        }

        $conClave = $resultados
            ->map(function (ResultadoEscala $resultado) use ($contexto): ?array {
                $escala = $contexto->escalas->firstWhere('id', $resultado->escala_id);

                if (! $escala instanceof Escala) {
                    return null;
                }

                return [
                    'clave' => $escala->clave,
                    'orden' => $escala->orden,
                    'valor' => $resultado->valor_normalizado ?? $resultado->puntaje_bruto,
                ];
            })
            ->filter()
            ->values();

        $ordenadas = $conClave->sort(function (array $uno, array $otro): int {
            $porValor = $otro['valor'] <=> $uno['valor'];

            return $porValor !== 0 ? $porValor : ($uno['orden'] <=> $otro['orden']);
        })->values();

        return $ordenadas->take($cuantas)
            ->map(static fn (array $fila): string => $fila['clave'])
            ->implode('');
    }

    private function textoCompleto(ReglaInterpretacion $regla): string
    {
        if ($regla->recomendaciones === null || trim($regla->recomendaciones) === '') {
            return $regla->texto_interpretacion;
        }

        return $regla->texto_interpretacion."\n\n".$regla->recomendaciones;
    }

    /**
     * Sustituye las variables del texto.
     *
     * `{nombre}` se resuelve VACÍO en aplicaciones anónimas y no con un
     * marcador: un texto que dijera «{nombre}, tus resultados…» en un
     * cuestionario de clima laboral rompería el anonimato al insinuar que el
     * sistema sí sabe quién es.
     *
     * @param  Collection<int, ResultadoEscala>  $resultados
     */
    private function resolverVariables(
        string $texto,
        ContextoCalificacion $contexto,
        Collection $resultados,
        ?int $escalaId,
    ): string {
        // Se pregunta por la LLAVE, no por la relación: en una aplicación
        // anónima no hay persona que cargar, y el nombre se resuelve vacío.
        $nombre = $contexto->aplicacion->persona_id === null
            ? ''
            : $contexto->aplicacion->persona->nombres;

        $variables = [
            '{nombre}' => $nombre,
            '{instrumento}' => $contexto->aplicacion->version->instrumento->nombre,
            '{fecha}' => $contexto->fechaDeAplicacion()->format('d/m/Y'),
        ];

        if ($escalaId !== null) {
            $resultado = $resultados->firstWhere('escala_id', $escalaId);

            if ($resultado instanceof ResultadoEscala) {
                $variables['{puntaje}'] = (string) $resultado->puntaje_bruto;
                $variables['{percentil}'] = $resultado->tipo_norma === 'percentil'
                    ? (string) $resultado->valor_normalizado
                    : '';
                $variables['{normalizado}'] = (string) ($resultado->valor_normalizado ?? '');
                $variables['{etiqueta}'] = $resultado->etiqueta_norma ?? '';
            }
        }

        return trim(strtr($texto, $variables));
    }
}
