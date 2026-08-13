<?php

declare(strict_types=1);

/*
 * Casos dorados del instrumento sintético.
 *
 * Este es el formato exacto que tendrán los del PHQ-9 y los del M-CHAT cuando
 * llegue su contenido: un juego de respuestas conocido y el resultado que el
 * pipeline tiene que producir.
 */

return [
    [
        'nombre' => 'Todo en cero',
        // R03 es inverso: un 0 vale (3+0) − 0 = 3.
        'respuestas' => ['R01' => 0, 'R02' => 0, 'R03' => 0],
        'esperado' => ['TOTAL' => ['bruto' => 3]],
    ],
    [
        'nombre' => 'Puntaje alto',
        // 3 + 3 + (3−0) = 6.
        'respuestas' => ['R01' => 3, 'R02' => 3, 'R03' => 3],
        'esperado' => ['TOTAL' => ['bruto' => 6]],
    ],
    [
        'nombre' => 'La reflexión del inverso importa',
        // Sin reflejar daría 1+1+3 = 5; con reflexión, 1+1+(3−3) = 2.
        'respuestas' => ['R01' => 1, 'R02' => 1, 'R03' => 3],
        'esperado' => ['TOTAL' => ['bruto' => 2]],
    ],
];
