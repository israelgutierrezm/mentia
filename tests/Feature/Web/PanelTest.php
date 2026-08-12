<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PanelTest extends TestCase
{
    public function test_el_panel_responde_una_pagina_inertia(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertOk();

        $respuesta->assertInertia(
            fn (AssertableInertia $pagina) => $pagina
                ->component('Panel')
                ->where('fase', 0)
        );
    }
}
