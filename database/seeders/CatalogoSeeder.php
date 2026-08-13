<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogo\Modelos\CategoriaInstrumento;
use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Catalogo\Modelos\PoblacionNorma;
use App\Domain\Catalogo\Modelos\TipoReactivo;
use Illuminate\Database\Seeder;

/**
 * Taxonomía del catálogo. IDEMPOTENTE.
 *
 * Aquí NO va contenido de instrumentos: eso es la Fase 4 y depende de archivos
 * de datos revisados. Esto es el andamio —dominios, tipos de reactivo,
 * categorías, poblaciones— sin el cual no se puede cargar ninguno.
 */
class CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $this->dominios();
        $this->tiposDeReactivo();
        $this->categorias();
        $this->poblaciones();
    }

    /**
     * Los "órganos" del expediente (Doc 03 §M5). Es la dimensión por la que el
     * perfil longitudinal compara a lo largo de la vida.
     */
    private function dominios(): void
    {
        $dominios = [
            ['desarrollo_temprano', 'Desarrollo temprano', 10],
            ['cognitivo', 'Cognitivo', 20],
            ['personalidad', 'Personalidad', 30],
            ['emocional', 'Emocional', 40],
            ['vocacional', 'Vocacional', 50],
            ['valores', 'Valores', 60],
            ['competencias', 'Competencias', 70],
            ['adaptativo', 'Adaptativo', 80],
            ['organizacional', 'Organizacional', 90],
        ];

        foreach ($dominios as [$clave, $nombre, $orden]) {
            Dominio::query()->updateOrCreate(['clave' => $clave], [
                'nombre' => $nombre,
                'orden' => $orden,
            ]);
        }
    }

    /**
     * Cada clave mapea a un componente de render del motor de aplicación
     * (Fase 6). `requiere_opciones` es lo que permite validar al importar que
     * un likert traiga sus opciones y que un texto abierto no las traiga.
     */
    private function tiposDeReactivo(): void
    {
        $tipos = [
            ['likert_3', 'Likert de 3 puntos', true, false],
            ['likert_4', 'Likert de 4 puntos', true, false],
            ['likert_5', 'Likert de 5 puntos', true, false],
            ['likert_7', 'Likert de 7 puntos', true, false],
            ['opcion_multiple_correcta', 'Opción múltiple con respuesta correcta', true, true],
            ['dicotomico', 'Dicotómico (sí/no)', true, false],
            ['eleccion_forzada_par', 'Elección forzada por pares', true, false],
            ['eleccion_forzada_cuadro', 'Elección forzada por cuadro (más/menos)', true, false],
            ['ranking', 'Ordenamiento por rango', true, false],
            ['diferencial_semantico', 'Diferencial semántico', true, false],
            ['matriz_visual', 'Matriz visual', true, true],
            ['texto_abierto', 'Texto abierto', false, false],
            ['captura_dibujo', 'Captura de dibujo', false, true],
            ['audio_respuesta', 'Respuesta en audio', false, true],
            ['observacional_examinador', 'Observacional del examinador', true, false],
            ['entrevista_estructurada', 'Entrevista estructurada', true, false],
        ];

        foreach ($tipos as [$clave, $nombre, $requiereOpciones, $admiteMultimedia]) {
            TipoReactivo::query()->updateOrCreate(['clave' => $clave], [
                'nombre' => $nombre,
                'requiere_opciones' => $requiereOpciones,
                'admite_multimedia' => $admiteMultimedia,
            ]);
        }
    }

    private function categorias(): void
    {
        /** @var array<string, array{nombre: string, orden: int, hijas: array<string, string>}> $arbol */
        $arbol = [
            'personalidad' => [
                'nombre' => 'Personalidad',
                'orden' => 10,
                'hijas' => [
                    'personalidad_laboral' => 'Laboral / conductual',
                    'personalidad_rasgos' => 'Rasgos',
                    'personalidad_clinica' => 'Clínica',
                    'personalidad_proyectiva' => 'Proyectivas',
                ],
            ],
            'inteligencia' => [
                'nombre' => 'Inteligencia y aptitudes',
                'orden' => 20,
                'hijas' => [
                    'factor_g' => 'Factor g',
                    'aptitudes_especificas' => 'Aptitudes específicas',
                ],
            ],
            'vocacional' => [
                'nombre' => 'Vocacional',
                'orden' => 30,
                'hijas' => ['intereses' => 'Intereses'],
            ],
            'valores' => [
                'nombre' => 'Valores y motivación',
                'orden' => 40,
                'hijas' => ['valores_motivacion' => 'Valores y motivación'],
            ],
            'emocional_clinico' => [
                'nombre' => 'Emocional / clínico breve',
                'orden' => 50,
                'hijas' => [
                    'tamizaje_depresion_ansiedad' => 'Tamizaje de depresión y ansiedad',
                    'bienestar_estres' => 'Bienestar y estrés',
                    'consumo_sustancias' => 'Consumo de sustancias',
                ],
            ],
            'cumplimiento' => [
                'nombre' => 'Cumplimiento normativo',
                'orden' => 60,
                'hijas' => ['nom035' => 'NOM-035-STPS-2018'],
            ],
            'desarrollo' => [
                'nombre' => 'Tamizaje de desarrollo',
                'orden' => 70,
                'hijas' => ['tamizaje_tea' => 'Tamizaje TEA / desarrollo'],
            ],
            'organizacional' => [
                'nombre' => 'Competencias y clima',
                'orden' => 80,
                'hijas' => [
                    'clima_organizacional' => 'Clima organizacional',
                    'competencias' => 'Competencias',
                ],
            ],
            'neuropsicologicas' => [
                'nombre' => 'Neuropsicológicas y atención',
                'orden' => 90,
                'hijas' => ['atencion' => 'Atención'],
            ],
        ];

        foreach ($arbol as $clave => $datos) {
            $padre = CategoriaInstrumento::query()->updateOrCreate(['clave' => $clave], [
                'padre_id' => null,
                'nombre' => $datos['nombre'],
                'orden' => $datos['orden'],
            ]);

            $orden = 0;

            foreach ($datos['hijas'] as $claveHija => $nombreHija) {
                CategoriaInstrumento::query()->updateOrCreate(['clave' => $claveHija], [
                    'padre_id' => $padre->id,
                    'nombre' => $nombreHija,
                    'orden' => $orden += 10,
                ]);
            }
        }
    }

    private function poblaciones(): void
    {
        $poblaciones = [
            ['general_mx', 'Población general mexicana', 'México'],
            ['adultos_mx', 'Adultos mexicanos', 'México'],
            ['escolar_mx', 'Población escolar mexicana', 'México'],
            ['laboral_mx', 'Población laboral mexicana', 'México'],
            ['internacional', 'Población internacional del manual', null],
        ];

        foreach ($poblaciones as [$clave, $nombre, $pais]) {
            PoblacionNorma::query()->updateOrCreate(['clave' => $clave], [
                'nombre' => $nombre,
                'pais' => $pais,
            ]);
        }
    }
}
