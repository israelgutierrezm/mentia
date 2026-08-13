<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | País de las normas
    |--------------------------------------------------------------------------
    |
    | Con qué población se resuelve la capa "nacional" de baremos (Doc 05 §2).
    | La prioridad completa es tenant → nacional → global: una norma mexicana
    | describe mejor a un adolescente de Oaxaca que una estadounidense, y la
    | del propio tenant mejor que las dos si tiene volumen para construirla.
    |
    */

    'pais_norma' => env('MENTIA_PAIS_NORMA', 'MX'),

    /*
    |--------------------------------------------------------------------------
    | Pipeline de calificación
    |--------------------------------------------------------------------------
    |
    | `detener_si_invalida`: una aplicación que la etapa 1 declara inválida no
    | sigue calificándose. Calcular sus puntajes produciría números que se ven
    | como resultados —una escala en 4 sobre 27 por haber contestado tres
    | reactivos no es un puntaje bajo, es un protocolo incompleto— y a esa
    | altura ya nadie los distingue.
    |
    | Es configurable porque el Doc 05 §2 lo pide así: hay investigación donde
    | se quiere el puntaje aunque el protocolo sea dudoso.
    |
    */

    'calificacion' => [
        'detener_si_invalida' => env('MENTIA_DETENER_SI_INVALIDA', true),
    ],

];
