# Archivos de datos de instrumentos

Aquí viven los instrumentos oficiales del sistema, versionados en el repositorio
(Doc 04 §final, Doc 08 Fase 4). El comando que los carga es:

```bash
php artisan mentia:seed-instrumentos          # todos
php artisan mentia:seed-instrumentos phq9     # uno
```

## Por qué archivos PHP y no Excel

El importador del Doc 04 lee Excel porque un tenant captura su contenido desde
una hoja de cálculo. El **seed oficial** es otra cosa: es contenido que se
revisa en un pull request, se compara línea a línea entre versiones y tiene que
poder llevar comentarios explicando de dónde salió cada corte.

Un `.xlsx` en git es un binario: no se diffea, no se comenta y no se revisa. Un
arreglo de PHP sí.

Los dos caminos entran por el MISMO servicio
(`ImportadorInstrumento::importarHojas`), así que comparten validación, reporte
fila a fila y rollback total. Una segunda ruta de importación con sus propias
reglas acabaría admitiendo contenido que la primera rechaza.

## Formato

Un archivo por instrumento, nombrado con su clave: `phq9.php`. Devuelve un
arreglo con las mismas hojas y columnas que la plantilla del Doc 04:

```php
return [
    'instrumento' => [[
        'clave' => 'phq9',
        'nombre' => 'Cuestionario de Salud del Paciente-9',
        'dominio' => 'emocional',
        'estatus_licencia' => 'dominio_publico',
        'nivel_sensibilidad' => '4',
        'modo_calificacion' => 'algoritmica',
        'quien_responde' => 'autoaplicada',
        'version' => '1.0',
    ]],
    'escalas' => [
        ['clave' => 'TOTAL', 'nombre' => 'Sintomatología depresiva', 'orden' => '1'],
    ],
    'bloques' => [
        ['clave' => 'B1', 'titulo' => 'Últimas dos semanas', 'orden' => '1'],
    ],
    'reactivos' => [
        ['codigo' => 'R01', 'bloque' => 'B1', 'tipo' => 'likert_4',
         'enunciado' => '…', 'orden' => '1', 'es_centinela' => '0'],
    ],
    'opciones' => [
        ['reactivo' => 'R01', 'codigo' => '0', 'texto' => 'Nunca', 'orden' => '1'],
    ],
    'claves' => [
        ['reactivo' => 'R01', 'escala' => 'TOTAL', 'peso' => '1'],
    ],
    'baremos' => [],
    'interpretaciones' => [
        ['escala' => 'TOTAL', 'tipo_regla' => 'rango_escala', 'tipo_puntaje' => 'bruto',
         'operador' => 'entre', 'valor_min' => '10', 'valor_max' => '14',
         'audiencia' => 'profesional', 'texto' => '…', 'bandera' => 'amarillo'],
    ],
];
```

Todos los valores van como **cadenas**: es lo que produce una hoja de cálculo y
lo que el importador espera. Un `0` numérico y un `'0'` de texto se comportan
distinto al validar, y mezclarlos es la clase de diferencia que sólo aparece en
producción.

## Casos dorados

Junto a cada instrumento, opcionalmente, `{clave}-casos.php`: juegos de
respuestas con el resultado esperado.

```php
return [
    [
        'nombre' => 'Depresión moderada',
        'respuestas' => ['R01' => 2, 'R02' => 2, /* … */],
        'esperado' => ['TOTAL' => ['bruto' => 18, 'etiqueta' => 'moderadamente_grave']],
    ],
];
```

`InstrumentosSembradosTest` los corre TODOS automáticamente. Cuando llegue el
contenido real del PHQ-9, la prueba de que califica bien ya está escrita: basta
poner los dos archivos.

## Lo que falta

**Este directorio está vacío de contenido real, y es el bloqueo de la Fase 4.**

Los reactivos del PHQ-9, del M-CHAT-R/F y de las Guías de Referencia de la
NOM-035 no se inventan: un instrumento sembrado con ítems aproximados produce
puntajes que parecen válidos y no lo son, y alguien tomaría decisiones clínicas
con ellos. Los textos tienen que venir de la fuente oficial —el DOF para la
NOM-035, la publicación original para el PHQ-9— revisados por quien responda por
ellos.

La maquinaria para cargarlos ya está: este cargador, el importador de Excel, el
pipeline de calificación y el arnés de casos dorados. Lo único que falta son los
archivos.
