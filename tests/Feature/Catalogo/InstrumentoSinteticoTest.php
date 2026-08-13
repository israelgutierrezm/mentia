<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

/**
 * El MISMO arnés, contra un instrumento sintético.
 *
 * Existe porque andamio sin probar se rompe justo el día que llega el
 * contenido. `InstrumentosSembradosTest` se salta mientras
 * `database/seeds/instrumentos` esté vacío; esta subclase lo apunta a un juego
 * de datos de prueba y lo recorre entero: cargar, publicar, no duplicar y
 * calificar sus casos dorados.
 *
 * El instrumento de `tests/Apoyo/instrumentos` es deliberadamente absurdo —sus
 * enunciados dicen «Reactivo sintético uno»— para que a nadie se le ocurra
 * aplicarlo. Vive en `tests/` y no en el directorio del contenido oficial.
 */
class InstrumentoSinteticoTest extends InstrumentosSembradosTest
{
    protected function directorio(): string
    {
        return base_path('tests/Apoyo/instrumentos');
    }
}
