<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Domain\Catalogo\Modelos\Bloque;
use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\TipoReactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;

/**
 * Arma instrumentos de prueba.
 *
 * `conTodosLosTiposDeReactivo()` es el que exige el Doc 08 para la Fase 3: si
 * el catálogo puede describir un instrumento que usa los dieciséis tipos, puede
 * describir cualquiera de la Ola 1 sin tocar código.
 */
class InstrumentoSintetico
{
    public Instrumento $instrumento;

    public VersionInstrumento $version;

    /** @var array<string, Escala> */
    public array $escalas = [];

    public Bloque $bloque;

    public function __construct(
        string $clave = 'sintetico',
        string $estatusLicencia = Instrumento::DOMINIO_PUBLICO,
        ?int $organizacionId = null,
        string $modoCalificacion = 'algoritmica',
    ) {
        $this->instrumento = Instrumento::query()->create([
            'organizacion_id' => $organizacionId,
            'clave' => $clave,
            'nombre' => 'Instrumento sintético '.$clave,
            'dominio_id' => Dominio::query()->where('clave', 'cognitivo')->value('id'),
            'estatus_licencia' => $estatusLicencia,
            'contenido_incluido' => $estatusLicencia === Instrumento::DOMINIO_PUBLICO
                ? 'completo'
                : 'esqueleto',
            'nivel_sensibilidad_id' => NivelSensibilidad::query()->where('nivel', 1)->value('id'),
            'modo_calificacion' => $modoCalificacion,
            'quien_responde' => 'autoaplicada',
        ]);

        $this->version = VersionInstrumento::query()->create([
            'instrumento_id' => $this->instrumento->id,
            'version' => '1.0',
            'idioma' => 'es-MX',
            'estado' => VersionInstrumento::BORRADOR,
        ]);

        $this->bloque = Bloque::query()->create([
            'version_instrumento_id' => $this->version->id,
            'clave' => 'B1',
            'titulo' => 'Bloque único',
            'orden' => 1,
        ]);
    }

    public function escala(string $clave, bool $esValidez = false): Escala
    {
        $escala = Escala::query()->create([
            'version_instrumento_id' => $this->version->id,
            'clave' => $clave,
            'nombre' => 'Escala '.$clave,
            'es_validez' => $esValidez,
            'orden' => count($this->escalas) + 1,
        ]);

        $this->escalas[$clave] = $escala;

        return $escala;
    }

    /**
     * Un reactivo del tipo pedido, con sus opciones si el tipo las requiere y
     * una clave que alimenta a la escala.
     *
     * @param  list<string>  $textosOpcion
     */
    public function reactivo(
        string $tipoClave,
        string $escalaClave,
        array $textosOpcion = ['Opción A', 'Opción B'],
        ?int $organizacionIdContenido = null,
        bool $esCentinela = false,
    ): Reactivo {
        $tipo = TipoReactivo::query()->where('clave', $tipoClave)->firstOrFail();
        $escala = $this->escalas[$escalaClave] ?? $this->escala($escalaClave);

        $orden = Reactivo::query()
            ->where('version_instrumento_id', $this->version->id)
            ->count() + 1;

        $reactivo = Reactivo::query()->create([
            'version_instrumento_id' => $this->version->id,
            'bloque_id' => $this->bloque->id,
            'tipo_reactivo_id' => $tipo->id,
            'codigo' => sprintf('R%03d', $orden),
            'enunciado' => 'Enunciado de prueba para '.$tipoClave,
            'organizacion_id_contenido' => $organizacionIdContenido,
            'es_centinela' => $esCentinela,
            'orden' => $orden,
        ]);

        if ($tipo->requiere_opciones) {
            foreach ($textosOpcion as $indice => $texto) {
                $opcion = OpcionReactivo::query()->create([
                    'reactivo_id' => $reactivo->id,
                    'codigo' => (string) $indice,
                    'texto' => $texto,
                    'organizacion_id_contenido' => $organizacionIdContenido,
                    'orden' => $indice,
                ]);

                ClaveCalificacion::query()->create([
                    'version_instrumento_id' => $this->version->id,
                    'reactivo_id' => $reactivo->id,
                    'opcion_id' => $opcion->id,
                    'escala_id' => $escala->id,
                    'peso' => $indice,
                ]);
            }

            return $reactivo;
        }

        // Sin opciones (texto abierto, dibujo, audio): la clave cuelga del
        // reactivo completo.
        ClaveCalificacion::query()->create([
            'version_instrumento_id' => $this->version->id,
            'reactivo_id' => $reactivo->id,
            'escala_id' => $escala->id,
            'peso' => 1,
        ]);

        return $reactivo;
    }

    /**
     * El caso que exige el Doc 08: un instrumento que usa CADA tipo de
     * reactivo del catálogo.
     */
    public static function conTodosLosTiposDeReactivo(): self
    {
        $sintetico = new self('todos_los_tipos');
        $sintetico->escala('E1');

        foreach (TipoReactivo::query()->orderBy('id')->get() as $tipo) {
            $sintetico->reactivo($tipo->clave, 'E1');
        }

        return $sintetico;
    }
}
