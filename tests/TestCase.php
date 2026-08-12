<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Las pruebas de PHP no dependen de que exista public/build.
         *
         * Sin esto, `php artisan test` en un checkout limpio falla con "Vite
         * manifest not found" en toda prueba que renderice una página Inertia
         * —un fallo que no dice nada del código que se está probando—. Quien
         * comprueba el frontend es `npm run build`, y en CI corre en su propio
         * trabajo.
         */
        $this->withoutVite();
    }
}
