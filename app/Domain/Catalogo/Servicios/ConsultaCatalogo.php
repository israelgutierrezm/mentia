<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Pagination\CursorPaginator;

/**
 * Consulta del catálogo. Web y API comparten esto.
 *
 * La ficha técnica NO incluye reactivos: el contenido de un instrumento se
 * entrega parcelado durante la aplicación (Doc 06 §3, protección de
 * contenido). Un endpoint de catálogo que devolviera los reactivos completos
 * sería la forma más cómoda de descargarse una prueba con copyright.
 */
class ConsultaCatalogo
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * @param  array<string, string|null>  $filtros
     * @return CursorPaginator<int, Instrumento>
     */
    public function buscar(array $filtros = [], int $porPagina = 25): CursorPaginator
    {
        $consulta = Instrumento::query()
            ->visiblesPara($this->contexto->id())
            ->with(['dominio', 'subcategoria', 'nivel'])
            ->withCount(['versiones as versiones_publicadas_count' => fn ($sub) => $sub->publicadas()]);

        if (($filtros['dominio'] ?? null) !== null) {
            $consulta->whereHas('dominio', fn ($sub) => $sub->where('clave', $filtros['dominio']));
        }

        if (($filtros['categoria'] ?? null) !== null) {
            $consulta->whereHas('subcategoria', fn ($sub) => $sub->where('clave', $filtros['categoria']));
        }

        if (($filtros['estatus_licencia'] ?? null) !== null) {
            $consulta->where('estatus_licencia', $filtros['estatus_licencia']);
        }

        if (($filtros['texto'] ?? null) !== null && $filtros['texto'] !== '') {
            $texto = '%'.$filtros['texto'].'%';

            $consulta->where(function ($sub) use ($texto): void {
                $sub->where('nombre', 'like', $texto)
                    ->orWhere('clave', 'like', $texto)
                    ->orWhere('nombre_corto', 'like', $texto);
            });
        }

        return $consulta->orderBy('nombre')->cursorPaginate($porPagina);
    }

    /**
     * Ficha técnica: qué es el instrumento, sus versiones y sus escalas.
     *
     * SIN reactivos, a propósito.
     *
     * @return array<string, mixed>
     */
    public function ficha(Instrumento $instrumento): array
    {
        $instrumento->load(['dominio', 'subcategoria', 'nivel']);

        $versiones = $instrumento->versiones()
            ->with('escalas')
            ->orderByDesc('id')
            ->get();

        $organizacionId = $this->contexto->id();

        return [
            'clave' => $instrumento->clave,
            'nombre' => $instrumento->nombre,
            'nombre_corto' => $instrumento->nombre_corto,
            'dominio' => $instrumento->dominio->nombre,
            'categoria' => $instrumento->subcategoria?->nombre,
            'estatus_licencia' => $instrumento->estatus_licencia,
            'contenido_incluido' => $instrumento->contenido_incluido,
            'nivel_sensibilidad' => $instrumento->nivelSensibilidad(),
            'modo_calificacion' => $instrumento->modo_calificacion,
            'quien_responde' => $instrumento->quien_responde,
            'edad_min_meses' => $instrumento->edad_min_meses,
            'edad_max_meses' => $instrumento->edad_max_meses,
            'duracion_estimada_min' => $instrumento->duracion_estimada_min,
            'requiere_supervision' => $instrumento->requiere_supervision,
            'se_aplica_en_linea' => $instrumento->seAplicaEnLinea(),
            'ficha' => [
                'autor' => $instrumento->autor,
                'anio' => $instrumento->anio,
                'poblacion_norma' => $instrumento->poblacion_norma,
                'referencia' => $instrumento->referencia_bibliografica,
            ],
            'versiones' => $versiones->map(fn (VersionInstrumento $version): array => [
                'id' => $version->id,
                'version' => $version->version,
                'idioma' => $version->idioma,
                'estado' => $version->estado,
                'publicada_en' => $version->publicada_en?->toIso8601String(),
                'escalas' => $version->escalas->map(fn ($escala): array => [
                    'clave' => $escala->clave,
                    'nombre' => $escala->nombre,
                    'es_validez' => $escala->es_validez,
                ])->all(),

                /*
                 * Cuántos reactivos hay, no cuáles. El conteo le sirve al
                 * administrador para saber si el instrumento está completo;
                 * los enunciados sólo salen durante la aplicación.
                 */
                'reactivos' => Reactivo::query()
                    ->where('version_instrumento_id', $version->id)
                    ->deContenidoVisiblePara($organizacionId)
                    ->count(),
            ])->all(),
        ];
    }

    /**
     * El estado de habilitación de cada instrumento para la organización
     * activa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function estadoDelTenant(): array
    {
        $organizacionId = $this->contexto->id();

        $registros = TenantInstrumento::query()
            ->with(['version.instrumento.dominio', 'firmante'])
            ->get()
            ->keyBy('version_instrumento_id');

        /*
         * `whereIn` contra una consulta de Instrumento en vez de `whereHas`
         * con clausura: así el scope `visiblesPara` se resuelve sobre su
         * propio modelo y la regla de qué instrumentos ve un tenant sigue
         * viviendo en un solo lugar.
         */
        $versiones = VersionInstrumento::query()
            ->publicadas()
            ->whereIn(
                'instrumento_id',
                Instrumento::query()->visiblesPara($organizacionId)->select('id')
            )
            ->with('instrumento.dominio')
            ->get();

        $salida = [];

        foreach ($versiones as $version) {
            $registro = $registros->get($version->id);
            $habilitado = $registro instanceof TenantInstrumento;

            $salida[] = [
                'version_id' => $version->id,
                'instrumento' => $version->instrumento->nombre,
                'clave' => $version->instrumento->clave,
                'dominio' => $version->instrumento->dominio->nombre,
                'version' => $version->etiqueta(),
                'estatus_licencia' => $version->instrumento->estatus_licencia,
                'exige_licencia' => $version->instrumento->exigeLicenciaDelTenant(),

                // Sin fila en tenant_instrumentos, el instrumento está en el
                // catálogo pero nadie lo encendió: `disponible`.
                'estado' => $habilitado ? $registro->estado : TenantInstrumento::DISPONIBLE,
                'se_puede_asignar' => $habilitado && $registro->sePuedeAsignar(),
                'declaracion_firmada_en' => $habilitado
                    ? $registro->declaracion_firmada_en?->toDateString()
                    : null,
                'firmante' => $habilitado
                    ? $registro->firmante?->nombreCompleto()
                    : null,
            ];
        }

        return $salida;
    }
}
