<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class EstadoTest extends TestCase
{
    public function test_la_api_responde_bajo_el_prefijo_versionado(): void
    {
        $respuesta = $this->getJson('/api/v1/estado');

        $respuesta->assertOk();
        $respuesta->assertJson([
            'version' => 'v1',
            'estado' => 'operativa',
        ]);
    }

    public function test_la_ruta_esta_nombrada_para_poder_versionarla(): void
    {
        $this->assertSame(
            url('/api/v1/estado'),
            route('api.v1.estado'),
            'Las rutas de la API se nombran api.v1.* para que la v2 pueda '
            .'convivir con la v1 sin colisionar.'
        );
    }

    public function test_la_api_no_vive_bajo_api_sin_version(): void
    {
        $this->getJson('/api/estado')->assertNotFound();
    }
}
