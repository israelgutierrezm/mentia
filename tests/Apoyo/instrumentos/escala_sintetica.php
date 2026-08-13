<?php

declare(strict_types=1);

/*
 * Instrumento SINTÉTICO para probar el cargador.
 *
 * No es una prueba psicométrica: los enunciados son deliberadamente absurdos
 * para que a nadie se le ocurra aplicarlo. Existe para demostrar que el
 * cargador, el importador, la publicación y el pipeline funcionan de punta a
 * punta con un archivo de datos real — de modo que el día que llegue el
 * contenido revisado, el andamio ya esté probado.
 *
 * Vive en `tests/`, NO en `database/seeds/instrumentos`: ahí sólo va contenido
 * oficial.
 */

return [
    'instrumento' => [
        [
            'clave' => 'escala_sintetica',
            'nombre' => 'Escala sintética de prueba',
            'dominio' => 'emocional',
            'estatus_licencia' => 'dominio_publico',
            'contenido_incluido' => 'completo',
            'nivel_sensibilidad' => '1',
            'modo_calificacion' => 'algoritmica',
            'quien_responde' => 'autoaplicada',
            'version' => '1.0',
        ],
    ],

    'escalas' => [
        ['clave' => 'TOTAL', 'nombre' => 'Puntaje total', 'orden' => '1'],
    ],

    'bloques' => [
        ['clave' => 'B1', 'titulo' => 'Bloque único', 'orden' => '1'],
    ],

    'reactivos' => [
        ['codigo' => 'R01', 'bloque' => 'B1', 'tipo' => 'likert_4',
            'enunciado' => 'Reactivo sintético uno.', 'orden' => '1'],
        ['codigo' => 'R02', 'bloque' => 'B1', 'tipo' => 'likert_4',
            'enunciado' => 'Reactivo sintético dos.', 'orden' => '2'],
        ['codigo' => 'R03', 'bloque' => 'B1', 'tipo' => 'likert_4',
            'enunciado' => 'Reactivo sintético tres, invertido.', 'orden' => '3',
            'es_inverso' => '1'],
    ],

    'opciones' => array_merge(...array_map(
        static fn (string $reactivo): array => array_map(
            static fn (int $valor): array => [
                'reactivo' => $reactivo,
                'codigo' => (string) $valor,
                'texto' => 'Opción '.$valor,
                'orden' => (string) ($valor + 1),
            ],
            [0, 1, 2, 3],
        ),
        ['R01', 'R02', 'R03'],
    )),

    /*
     * Claves SIN opción: el valor de la respuesta suma a la escala. Es la forma
     * de `suma_simple`, la que usan el PHQ-9 y el GAD-7.
     */
    'claves' => [
        ['reactivo' => 'R01', 'escala' => 'TOTAL', 'peso' => '1'],
        ['reactivo' => 'R02', 'escala' => 'TOTAL', 'peso' => '1'],
        ['reactivo' => 'R03', 'escala' => 'TOTAL', 'peso' => '1'],
    ],

    'baremos' => [],

    /*
     * Qué etapas corre y con qué estrategia (Doc 05 §1.3). Sin esta hoja el
     * instrumento carga, publica y se asigna — y no calcula nada.
     */
    'pipeline' => [
        ['etapa' => 'validez', 'estrategia' => 'omisiones_max',
            'orden' => '1', 'param_umbral_pct' => '50'],
        ['etapa' => 'brutos', 'estrategia' => 'suma_simple', 'orden' => '1'],
    ],

    'interpretaciones' => [
        [
            'escala' => 'TOTAL', 'tipo_regla' => 'rango_escala', 'tipo_puntaje' => 'bruto',
            'operador' => '>=', 'valor_min' => '6',
            'audiencia' => 'profesional', 'prioridad' => '1',
            'texto' => 'Puntaje alto en la escala sintética.',
            'bandera' => 'amarillo',
        ],
    ],
];
