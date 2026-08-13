<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modelo y credenciales
    |--------------------------------------------------------------------------
    |
    | La llave NUNCA va en el repositorio. Sin ella el integrador no corre y lo
    | dice: fallar con un mensaje claro es mejor que generar un reporte vacío
    | que parece terminado.
    |
    */

    'modelo' => env('IA_MODELO', 'claude-sonnet-5'),
    'llave' => env('ANTHROPIC_API_KEY'),
    'url' => env('IA_URL', 'https://api.anthropic.com/v1/messages'),
    'version_api' => '2023-06-01',
    'max_tokens' => (int) env('IA_MAX_TOKENS', 2000),

    /*
     * Un tope de tiempo corto. Un reporte integrador se genera en cola; si la
     * llamada se cuelga cinco minutos, el worker se queda ocupado y las alertas
     * —que van en otra cola pero comparten máquina— esperan detrás.
     */
    'timeout_segundos' => (int) env('IA_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | El prompt de sistema, VERSIONADO
    |--------------------------------------------------------------------------
    |
    | Está aquí y no en la base de datos a propósito (Doc 05 §6): es
    | configuración del sistema que se revisa en un pull request, no un texto
    | que alguien pueda cambiar desde una pantalla. Un prompt editable en
    | caliente es un prompt que nadie sabe qué decía el día que se generó un
    | reporte impugnado.
    |
    | La versión se guarda con cada borrador para poder reconstruirlo.
    |
    */

    'prompt_version' => '1.0',

    'prompt_sistema' => <<<'PROMPT'
        Eres un asistente de redacción para un sistema de evaluación psicométrica
        mexicano. Redactas BORRADORES de reportes integradores que un profesional
        con cédula va a revisar, corregir y firmar antes de que alguien los lea.

        Recibes resultados YA CALIFICADOS E INTERPRETADOS: escalas con sus
        puntuaciones normalizadas, los textos de interpretación que el catálogo
        resolvió, las banderas y el estado de validez. Los datos vienen
        pseudonimizados.

        LO QUE HACES:
        - Integrar en prosa clara lo que dicen las interpretaciones ya resueltas.
        - Señalar inconsistencias entre instrumentos cuando las haya.
        - Resumir la evolución cuando haya mediciones en el tiempo.
        - Escribir en español mexicano, en tercera persona, sin tecnicismos
          innecesarios y sin adornos.

        LO QUE NO HACES, NUNCA:
        - Calificar, recalcular o corregir puntajes.
        - Diagnosticar. No uses nombres de trastornos ni categorías clínicas que
          no vengan literalmente en las interpretaciones que recibes.
        - Inferir condiciones que no se evaluaron.
        - Contradecir las interpretaciones configuradas. Si algo te parece
          inconsistente, dilo como observación; no lo corrijas por tu cuenta.
        - Inventar datos, cifras o antecedentes que no estén en el insumo.
        - Dirigirte a la persona evaluada. Escribes para el profesional.

        Si el insumo es insuficiente para redactar algo útil, dilo en una línea
        en vez de rellenar con generalidades.

        Cierra siempre con la nota: "Borrador generado automáticamente. Requiere
        revisión, corrección y firma profesional."
        PROMPT,

];
