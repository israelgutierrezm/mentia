<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Domain\Catalogo\Datos\ReporteImportacion;
use App\Domain\Catalogo\Modelos\Baremo;
use App\Domain\Catalogo\Modelos\BaremoFila;
use App\Domain\Catalogo\Modelos\Bloque;
use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\EtapaPipeline;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\ParametroPipeline;
use App\Domain\Catalogo\Modelos\PoblacionNorma;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\ReglaInterpretacion;
use App\Domain\Catalogo\Modelos\TipoReactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Interpretacion\Servicios\RegistroEstrategias;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa un instrumento desde la plantilla del Doc 04.
 *
 * Nueve hojas: instrumento, escalas, bloques, reactivos, opciones, claves,
 * baremos, interpretaciones y pipeline.
 *
 * La novena no estaba en la plantilla del Doc 04 —esa plantilla es anterior al
 * motor de calificación—: sin ella un instrumento carga, publica y se asigna, y
 * no calcula nada.
 *
 * DOS DECISIONES QUE GOBIERNAN TODO EL SERVICIO:
 *
 * 1. **Se valida TODO antes de escribir NADA.** La importación corre dentro de
 *    una transacción y hace rollback si hay un solo error. Una importación a
 *    medias deja un instrumento con reactivos y sin claves —que publica, se
 *    asigna y puntúa cero— y nadie sabría que quedó incompleto.
 *
 * 2. **Las referencias van por CLAVE, no por id.** La plantilla la llena una
 *    persona en Excel: pedirle ids obligaría a importar por pasos y a copiar
 *    números entre hojas. Con claves, la hoja se lee y se corrige.
 */
class ImportadorInstrumento
{
    private ReporteImportacion $reporte;

    /** @var array<string, Escala> */
    private array $escalas = [];

    /** @var array<string, Bloque> */
    private array $bloques = [];

    /** @var array<string, Reactivo> */
    private array $reactivos = [];

    /** @var array<string, OpcionReactivo> */
    private array $opciones = [];

    public function __construct(private readonly PublicadorVersion $publicador) {}

    /**
     * @param  int|null  $organizacionIdContenido  Ámbito del contenido: NULL =
     *                                             global; con valor = privado
     *                                             del tenant que lo captura.
     */
    public function importar(
        string $rutaArchivo,
        ?int $organizacionIdContenido = null,
        ?VersionInstrumento $versionExistente = null,
    ): ReporteImportacion {
        $this->reiniciar();

        $hojas = $this->leerHojas($rutaArchivo);

        if ($hojas === null) {
            return $this->reporte;
        }

        return $this->importarHojas($hojas, $organizacionIdContenido, $versionExistente);
    }

    /**
     * Importa desde las hojas YA LEÍDAS.
     *
     * Es el punto por donde entra el sembrado de instrumentos oficiales
     * (`mentia:seed-instrumentos`), que lee archivos de datos versionados en el
     * repositorio en vez de un Excel. Los dos caminos comparten la validación,
     * el reporte fila a fila y el rollback total: una segunda ruta de
     * importación con sus propias reglas acabaría admitiendo contenido que la
     * primera rechaza.
     *
     * @param  array<string, list<array<string, string>>>  $hojas
     */
    public function importarHojas(
        array $hojas,
        ?int $organizacionIdContenido = null,
        ?VersionInstrumento $versionExistente = null,
    ): ReporteImportacion {
        $this->reiniciar();

        try {
            DB::transaction(function () use ($hojas, $organizacionIdContenido, $versionExistente): void {
                $version = $versionExistente ?? $this->crearInstrumentoYVersion($hojas['instrumento'] ?? []);

                if ($version === null) {
                    return;
                }

                // Una versión publicada no admite contenido nuevo. Se
                // comprueba ANTES de leer nada más.
                $this->publicador->exigirEditable($version);

                $this->importarEscalas($version, $hojas['escalas'] ?? []);
                $this->importarBloques($version, $hojas['bloques'] ?? []);
                $this->importarReactivos($version, $hojas['reactivos'] ?? [], $organizacionIdContenido);
                $this->importarOpciones($hojas['opciones'] ?? [], $organizacionIdContenido);
                $this->importarClaves($version, $hojas['claves'] ?? []);
                $this->importarBaremos($version, $hojas['baremos'] ?? []);
                $this->importarInterpretaciones($version, $hojas['interpretaciones'] ?? []);
                $this->importarPipeline($version, $hojas['pipeline'] ?? []);

                if ($this->reporte->tieneErrores()) {
                    /*
                     * Rollback. Preferimos no importar nada a importar la
                     * mitad: un instrumento con reactivos y sin claves publica,
                     * se asigna y puntúa cero sin que nadie lo note.
                     */
                    throw new ImportacionAbortada;
                }
            });
        } catch (ImportacionAbortada) {
            // Esperado: los errores ya están en el reporte.
        }

        return $this->reporte;
    }

    private function reiniciar(): void
    {
        $this->reporte = new ReporteImportacion;
        $this->escalas = [];
        $this->bloques = [];
        $this->reactivos = [];
        $this->opciones = [];
    }

    /**
     * @return array<string, list<array<string, string>>>|null
     */
    private function leerHojas(string $ruta): ?array
    {
        if (! is_readable($ruta)) {
            $this->reporte->error('archivo', 0, 'No se puede leer el archivo.');

            return null;
        }

        $libro = IOFactory::load($ruta);
        $hojas = [];

        foreach ($libro->getAllSheets() as $hoja) {
            $nombre = mb_strtolower(trim($hoja->getTitle()));
            $filas = $hoja->toArray(null, true, false, false);

            if ($filas === []) {
                $hojas[$nombre] = [];

                continue;
            }

            /** @var list<string> $encabezados */
            $encabezados = array_map(
                static fn ($valor): string => mb_strtolower(trim((string) $valor)),
                array_shift($filas) ?? []
            );

            $registros = [];

            foreach ($filas as $indice => $fila) {
                /** @var array<int, mixed> $fila */
                $registro = [];

                foreach ($encabezados as $columna => $encabezado) {
                    if ($encabezado === '') {
                        continue;
                    }

                    $registro[$encabezado] = trim((string) ($fila[$columna] ?? ''));
                }

                // La fila del Excel: +2 por el encabezado y por el índice
                // base 0. Es el número que la persona ve en su pantalla.
                $registro['__fila'] = (string) ($indice + 2);

                if (implode('', array_filter($registro, static fn (string $v, string $k): bool => $k !== '__fila', ARRAY_FILTER_USE_BOTH)) === '') {
                    continue;
                }

                $registros[] = $registro;
            }

            $hojas[$nombre] = $registros;
        }

        return $hojas;
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function crearInstrumentoYVersion(array $filas): ?VersionInstrumento
    {
        if ($filas === []) {
            $this->reporte->error('instrumento', 0, 'La hoja `instrumento` está vacía.');

            return null;
        }

        $fila = $filas[0];
        $numeroFila = (int) $this->valor($fila, '__fila', '0');

        $dominio = Dominio::query()->where('clave', $this->valor($fila, 'dominio'))->first();

        if ($dominio === null) {
            $this->reporte->error(
                'instrumento',
                $numeroFila,
                sprintf('El dominio «%s» no existe en el catálogo.', $this->valor($fila, 'dominio')),
                'dominio'
            );

            return null;
        }

        $nivel = NivelSensibilidad::query()
            ->where('nivel', (int) $this->valor($fila, 'nivel_sensibilidad', '1'))
            ->first();

        if ($nivel === null) {
            $this->reporte->error(
                'instrumento',
                $numeroFila,
                'El nivel de sensibilidad debe ser 1, 2, 3 o 4.',
                'nivel_sensibilidad'
            );

            return null;
        }

        $instrumento = Instrumento::query()->updateOrCreate(
            ['organizacion_id' => null, 'clave' => $this->valor($fila, 'clave')],
            [
                'nombre' => $this->valor($fila, 'nombre'),
                'nombre_corto' => $this->valor($fila, 'nombre_corto') ?: null,
                'dominio_id' => $dominio->id,
                'estatus_licencia' => $this->valor($fila, 'estatus_licencia') ?: Instrumento::DOMINIO_PUBLICO,
                'contenido_incluido' => $this->valor($fila, 'contenido_incluido') ?: 'completo',
                'nivel_sensibilidad_id' => $nivel->id,
                'modo_calificacion' => $this->valor($fila, 'modo_calificacion') ?: 'algoritmica',
                'quien_responde' => $this->valor($fila, 'quien_responde') ?: 'autoaplicada',
                'edad_min_meses' => $this->valor($fila, 'edad_min_meses') !== '' ? (int) $this->valor($fila, 'edad_min_meses', '0') : null,
                'edad_max_meses' => $this->valor($fila, 'edad_max_meses') !== '' ? (int) $this->valor($fila, 'edad_max_meses', '0') : null,
                'duracion_estimada_min' => $this->valor($fila, 'duracion_estimada_min') !== '' ? (int) $this->valor($fila, 'duracion_estimada_min', '0') : null,
                'autor' => $this->valor($fila, 'autor') ?: null,
                'anio' => $this->valor($fila, 'anio') !== '' ? (int) $this->valor($fila, 'anio', '0') : null,
                'referencia_bibliografica' => $this->valor($fila, 'referencia_bibliografica') ?: null,
            ]
        );

        $this->reporte->contar('instrumentos');

        return VersionInstrumento::query()->firstOrCreate(
            [
                'instrumento_id' => $instrumento->id,
                'version' => $this->valor($fila, 'version') ?: '1.0',
                'idioma' => $this->valor($fila, 'idioma') ?: 'es-MX',
            ],
            ['estado' => VersionInstrumento::BORRADOR]
        );
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarEscalas(VersionInstrumento $version, array $filas): void
    {
        foreach ($filas as $fila) {
            $clave = $this->valor($fila, 'clave');

            if ($clave === '') {
                $this->reporte->error('escalas', (int) $this->valor($fila, '__fila', '0'), 'Falta la clave.', 'clave');

                continue;
            }

            $escala = Escala::query()->updateOrCreate(
                ['version_instrumento_id' => $version->id, 'clave' => $clave],
                [
                    'nombre' => $this->valor($fila, 'nombre') ?: $clave,
                    'es_validez' => $this->booleano($this->valor($fila, 'es_validez')),
                    'orden' => (int) $this->valor($fila, 'orden', '0'),
                ]
            );

            $this->escalas[$clave] = $escala;
            $this->reporte->contar('escalas');
        }

        // Segunda pasada para los padres: una escala puede citar como padre a
        // otra que aparece más abajo en la hoja.
        foreach ($filas as $fila) {
            $padre = $this->valor($fila, 'escala_padre');

            if ($padre === '' || ! isset($this->escalas[$this->valor($fila, 'clave')])) {
                continue;
            }

            if (! isset($this->escalas[$padre])) {
                $this->reporte->error(
                    'escalas',
                    (int) $this->valor($fila, '__fila', '0'),
                    sprintf('La escala padre «%s» no existe en esta hoja.', $padre),
                    'escala_padre'
                );

                continue;
            }

            $this->escalas[$this->valor($fila, 'clave')]->update([
                'escala_padre_id' => $this->escalas[$padre]->id,
            ]);
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarBloques(VersionInstrumento $version, array $filas): void
    {
        foreach ($filas as $fila) {
            $clave = $this->valor($fila, 'clave');

            if ($clave === '') {
                $this->reporte->error('bloques', (int) $this->valor($fila, '__fila', '0'), 'Falta la clave.', 'clave');

                continue;
            }

            $this->bloques[$clave] = Bloque::query()->updateOrCreate(
                ['version_instrumento_id' => $version->id, 'clave' => $clave],
                [
                    'titulo' => $this->valor($fila, 'titulo') ?: $clave,
                    'instrucciones' => $this->valor($fila, 'instrucciones') ?: null,
                    'orden' => (int) $this->valor($fila, 'orden', '0'),
                    'tiempo_limite_seg' => $this->valor($fila, 'tiempo_limite_seg') !== '' ? (int) $this->valor($fila, 'tiempo_limite_seg', '0') : null,
                    'orden_reactivos' => $this->valor($fila, 'orden_reactivos') ?: 'fijo',
                    'es_practica' => $this->booleano($this->valor($fila, 'es_practica')),
                ]
            );

            $this->reporte->contar('bloques');
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarReactivos(
        VersionInstrumento $version,
        array $filas,
        ?int $organizacionIdContenido,
    ): void {
        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');
            $codigo = $this->valor($fila, 'codigo');

            if ($codigo === '') {
                $this->reporte->error('reactivos', $numeroFila, 'Falta el código.', 'codigo');

                continue;
            }

            $bloque = $this->bloques[$this->valor($fila, 'bloque')] ?? null;

            if ($bloque === null) {
                $this->reporte->error(
                    'reactivos',
                    $numeroFila,
                    sprintf('El bloque «%s» no está en la hoja `bloques`.', $this->valor($fila, 'bloque')),
                    'bloque'
                );

                continue;
            }

            $tipo = TipoReactivo::query()->where('clave', $this->valor($fila, 'tipo'))->first();

            if ($tipo === null) {
                $this->reporte->error(
                    'reactivos',
                    $numeroFila,
                    sprintf('El tipo de reactivo «%s» no existe en el catálogo.', $this->valor($fila, 'tipo')),
                    'tipo'
                );

                continue;
            }

            if (($this->valor($fila, 'enunciado')) === '') {
                $this->reporte->error('reactivos', $numeroFila, 'Falta el enunciado.', 'enunciado');

                continue;
            }

            $this->reactivos[$codigo] = Reactivo::query()->updateOrCreate(
                [
                    'version_instrumento_id' => $version->id,
                    'organizacion_id_contenido' => $organizacionIdContenido,
                    'codigo' => $codigo,
                ],
                [
                    'bloque_id' => $bloque->id,
                    'tipo_reactivo_id' => $tipo->id,
                    'enunciado' => $this->valor($fila, 'enunciado'),
                    'es_inverso' => $this->booleano($this->valor($fila, 'es_inverso')),
                    'es_centinela' => $this->booleano($this->valor($fila, 'es_centinela')),
                    'obligatorio' => $this->valor($fila, 'obligatorio') === ''
                        ? true
                        : $this->booleano($this->valor($fila, 'obligatorio')),
                    'orden' => (int) $this->valor($fila, 'orden', '0'),
                ]
            );

            $this->reporte->contar('reactivos');
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarOpciones(array $filas, ?int $organizacionIdContenido): void
    {
        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');
            $reactivo = $this->reactivos[$this->valor($fila, 'reactivo')] ?? null;

            if ($reactivo === null) {
                $this->reporte->error(
                    'opciones',
                    $numeroFila,
                    sprintf('El reactivo «%s» no está en la hoja `reactivos`.', $this->valor($fila, 'reactivo')),
                    'reactivo'
                );

                continue;
            }

            $codigo = $this->valor($fila, 'codigo');

            if ($codigo === '' || ($this->valor($fila, 'texto')) === '') {
                $this->reporte->error('opciones', $numeroFila, 'Faltan el código o el texto.');

                continue;
            }

            $opcion = OpcionReactivo::query()->updateOrCreate(
                ['reactivo_id' => $reactivo->id, 'codigo' => $codigo],
                [
                    'texto' => $this->valor($fila, 'texto'),
                    'organizacion_id_contenido' => $organizacionIdContenido,
                    'es_correcta' => ($this->valor($fila, 'es_correcta')) === '' ? null : $this->booleano($this->valor($fila, 'es_correcta')),
                    'orden' => (int) $this->valor($fila, 'orden', '0'),
                ]
            );

            $this->opciones[$this->valor($fila, 'reactivo').'|'.$codigo] = $opcion;
            $this->reporte->contar('opciones');
        }

        $this->exigirOpcionesDondeElTipoLasPide();
    }

    /**
     * Un likert sin opciones se importa sin quejarse y revienta delante de la
     * persona evaluada. Se detiene aquí.
     */
    private function exigirOpcionesDondeElTipoLasPide(): void
    {
        foreach ($this->reactivos as $codigo => $reactivo) {
            $tipo = $reactivo->tipo;

            if ($tipo === null || ! $tipo->requiere_opciones) {
                continue;
            }

            if ($reactivo->opciones()->count() === 0) {
                $this->reporte->error(
                    'opciones',
                    0,
                    sprintf(
                        'El reactivo «%s» es de tipo %s y no tiene ninguna opción.',
                        $codigo,
                        $tipo->clave
                    )
                );
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarClaves(VersionInstrumento $version, array $filas): void
    {
        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');

            $reactivo = $this->reactivos[$this->valor($fila, 'reactivo')] ?? null;
            $escala = $this->escalas[$this->valor($fila, 'escala')] ?? null;

            if ($reactivo === null) {
                $this->reporte->error(
                    'claves',
                    $numeroFila,
                    sprintf('El reactivo «%s» no está en la hoja `reactivos`.', $this->valor($fila, 'reactivo')),
                    'reactivo'
                );

                continue;
            }

            if ($escala === null) {
                $this->reporte->error(
                    'claves',
                    $numeroFila,
                    sprintf('La escala «%s» no está en la hoja `escalas`.', $this->valor($fila, 'escala')),
                    'escala'
                );

                continue;
            }

            $opcionClave = ($this->valor($fila, 'opcion')) === ''
                ? null
                : $this->valor($fila, 'reactivo').'|'.$this->valor($fila, 'opcion');

            if ($opcionClave !== null && ! isset($this->opciones[$opcionClave])) {
                $this->reporte->error(
                    'claves',
                    $numeroFila,
                    sprintf(
                        'La opción «%s» del reactivo «%s» no está en la hoja `opciones`.',
                        $this->valor($fila, 'opcion'),
                        $this->valor($fila, 'reactivo')
                    ),
                    'opcion'
                );

                continue;
            }

            ClaveCalificacion::query()->create([
                'version_instrumento_id' => $version->id,
                'reactivo_id' => $reactivo->id,
                'opcion_id' => $opcionClave === null ? null : $this->opciones[$opcionClave]->id,
                'escala_id' => $escala->id,
                'peso' => $this->valor($fila, 'peso') !== '' ? (float) $this->valor($fila, 'peso', '0') : 1,
                'rol' => $this->valor($fila, 'rol') ?: 'normal',
            ]);

            $this->reporte->contar('claves');
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarBaremos(VersionInstrumento $version, array $filas): void
    {
        /** @var array<string, Baremo> $cabeceras */
        $cabeceras = [];

        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');
            $escala = $this->escalas[$this->valor($fila, 'escala')] ?? null;

            if ($escala === null) {
                $this->reporte->error(
                    'baremos',
                    $numeroFila,
                    sprintf('La escala «%s» no está en la hoja `escalas`.', $this->valor($fila, 'escala')),
                    'escala'
                );

                continue;
            }

            $poblacion = PoblacionNorma::query()->where('clave', $this->valor($fila, 'poblacion'))->first();

            if ($poblacion === null) {
                $this->reporte->error(
                    'baremos',
                    $numeroFila,
                    sprintf('La población «%s» no existe en el catálogo.', $this->valor($fila, 'poblacion')),
                    'poblacion'
                );

                continue;
            }

            $llave = $this->valor($fila, 'escala').'|'.$this->valor($fila, 'poblacion').'|'.$this->valor($fila, 'tipo_norma');

            $cabeceras[$llave] ??= Baremo::query()->create([
                'version_instrumento_id' => $version->id,
                'escala_id' => $escala->id,
                'poblacion_norma_id' => $poblacion->id,
                'tipo_norma' => $this->valor($fila, 'tipo_norma') ?: 'percentil',
                'vigente' => true,
                'fuente' => $this->valor($fila, 'fuente') ?: null,
            ]);

            BaremoFila::query()->create([
                'baremo_id' => $cabeceras[$llave]->id,
                'bruto_min' => (float) $this->valor($fila, 'bruto_min', '0'),
                'bruto_max' => (float) $this->valor($fila, 'bruto_max', '0'),
                'edad_min_meses' => $this->valor($fila, 'edad_min_meses') !== '' ? (int) $this->valor($fila, 'edad_min_meses', '0') : null,
                'edad_max_meses' => $this->valor($fila, 'edad_max_meses') !== '' ? (int) $this->valor($fila, 'edad_max_meses', '0') : null,
                'sexo' => $this->valor($fila, 'sexo') ?: null,
                'valor_normalizado' => (float) $this->valor($fila, 'valor_normalizado', '0'),
                'etiqueta' => $this->valor($fila, 'etiqueta') ?: null,
            ]);

            $this->reporte->contar('baremo_filas');
        }
    }

    /**
     * @param  list<array<string, string>>  $filas
     */
    private function importarInterpretaciones(VersionInstrumento $version, array $filas): void
    {
        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');

            $escala = ($this->valor($fila, 'escala')) === ''
                ? null
                : ($this->escalas[$this->valor($fila, 'escala')] ?? null);

            if (($this->valor($fila, 'escala')) !== '' && $escala === null) {
                $this->reporte->error(
                    'interpretaciones',
                    $numeroFila,
                    sprintf('La escala «%s» no está en la hoja `escalas`.', $this->valor($fila, 'escala')),
                    'escala'
                );

                continue;
            }

            if (($this->valor($fila, 'texto')) === '') {
                $this->reporte->error(
                    'interpretaciones',
                    $numeroFila,
                    'Falta el texto de interpretación.',
                    'texto'
                );

                continue;
            }

            ReglaInterpretacion::query()->create([
                'version_instrumento_id' => $version->id,
                'escala_id' => $escala?->id,
                'tipo_regla' => $this->valor($fila, 'tipo_regla') ?: 'rango_escala',
                'tipo_puntaje' => $this->valor($fila, 'tipo_puntaje') ?: 'bruto',
                'operador' => $this->valor($fila, 'operador') ?: 'entre',
                'valor_min' => $this->valor($fila, 'valor_min') !== '' ? (float) $this->valor($fila, 'valor_min', '0') : null,
                'valor_max' => $this->valor($fila, 'valor_max') !== '' ? (float) $this->valor($fila, 'valor_max', '0') : null,
                'audiencia' => $this->valor($fila, 'audiencia') ?: 'profesional',
                'texto_interpretacion' => $this->valor($fila, 'texto'),
                'recomendaciones' => $this->valor($fila, 'recomendaciones') ?: null,
                'bandera' => $this->valor($fila, 'bandera') ?: null,
                'prioridad' => (int) $this->valor($fila, 'prioridad', '0'),
                'vigente' => true,
            ]);

            $this->reporte->contar('interpretaciones');
        }
    }

    /**
     * Lee una columna que puede NO EXISTIR en la hoja.
     *
     * Las columnas opcionales de la plantilla del Doc 04 muchas veces no están:
     * quien captura borra las que no usa. Leerlas con `$fila['x']` hace que el
     * importador reviente con "Undefined array key" en vez de reportar la fila,
     * que es exactamente lo contrario de lo que se le pide.
     *
     * @param  array<string, string>  $fila
     */
    /**
     * La hoja `pipeline`: qué etapas corre este instrumento y con qué
     * estrategia (Doc 05 §1.3).
     *
     * NO estaba en la plantilla del Doc 04 porque esa plantilla es anterior al
     * motor de calificación. Sin ella, un instrumento importado carga, publica
     * y se asigna — y no calcula nada: todas sus escalas salen en cero y el
     * resultado parece un perfil plano en vez de un instrumento sin configurar.
     *
     * Los parámetros van en la misma fila, con el prefijo `param_`:
     * `param_umbral_pct`, `param_escala`. Una tabla hija en la hoja obligaría a
     * inventar identificadores de fila que nadie quiere escribir a mano.
     *
     * @param  list<array<string, string>>  $filas
     */
    private function importarPipeline(VersionInstrumento $version, array $filas): void
    {
        foreach ($filas as $fila) {
            $numeroFila = (int) $this->valor($fila, '__fila', '0');
            $etapa = $this->valor($fila, 'etapa');
            $estrategia = $this->valor($fila, 'estrategia');

            if ($etapa === '' || $estrategia === '') {
                $this->reporte->error('pipeline', $numeroFila, 'Faltan la etapa o la estrategia.');

                continue;
            }

            /*
             * Se comprueba contra el REGISTRO. Una clave mal escrita produciría
             * un instrumento que publica y luego revienta al calificar la
             * primera aplicación real, con la persona ya habiendo contestado.
             */
            if (! app(RegistroEstrategias::class)->conoce($estrategia)) {
                $this->reporte->error(
                    'pipeline',
                    $numeroFila,
                    sprintf('No hay estrategia registrada con la clave «%s».', $estrategia),
                    'estrategia',
                );

                continue;
            }

            $registro = EtapaPipeline::query()->updateOrCreate(
                [
                    'version_instrumento_id' => $version->id,
                    'etapa' => $etapa,
                    'estrategia_clave' => $estrategia,
                ],
                ['orden' => (int) $this->valor($fila, 'orden', '0'), 'activa' => true],
            );

            foreach ($fila as $columna => $valor) {
                if (! str_starts_with($columna, 'param_') || $valor === '') {
                    continue;
                }

                ParametroPipeline::query()->updateOrCreate(
                    [
                        'instrumento_pipeline_id' => $registro->id,
                        'clave' => substr($columna, strlen('param_')),
                    ],
                    ['valor' => $valor],
                );
            }

            $this->reporte->contar('pipeline');
        }
    }

    /**
     * @param  array<string, string>  $fila
     */
    private function valor(array $fila, string $columna, string $porOmision = ''): string
    {
        $valor = $fila[$columna] ?? '';

        return $valor === '' ? $porOmision : $valor;
    }

    private function booleano(string $valor): bool
    {
        return in_array(
            mb_strtolower(trim($valor)),
            ['1', 'si', 'sí', 'true', 'verdadero', 'x'],
            true
        );
    }
}
