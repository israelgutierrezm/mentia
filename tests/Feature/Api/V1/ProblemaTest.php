<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * El contrato de errores de la API (RFC 7807, Doc 07).
 *
 * Se prueba desde la Fase 0 porque es transversal: si el envoltorio de error
 * cambia de forma después de que la app Flutter y los ATS ya integraron, el
 * costo lo pagan ellos.
 */
class ProblemaTest extends TestCase
{
    public function test_una_ruta_inexistente_de_la_api_responde_problem_json(): void
    {
        $respuesta = $this->getJson('/api/v1/no-existe');

        $respuesta->assertNotFound();
        $respuesta->assertHeader('Content-Type', 'application/problem+json');

        $respuesta->assertJsonStructure(['type', 'title', 'status', 'detail']);
        $respuesta->assertJson([
            'status' => 404,
            'title' => 'No encontrado',
        ]);
    }

    public function test_el_detalle_de_un_404_no_confirma_si_el_recurso_existe(): void
    {
        /*
         * Una ruta que NO existe bajo /api/v1. Se eligió a propósito una que
         * ninguna fase va a definir: apuntar a un recurso real hace que la
         * prueba empiece a medir otra cosa —un 401 de autenticación— en cuanto
         * ese endpoint se implementa. Ya pasó con /personas y con /catalogo.
         */
        $respuesta = $this->getJson('/api/v1/ruta-que-nunca-existira/9999');

        /*
         * "no existe O no está a tu alcance" es deliberado: distinguir los dos
         * casos le diría a quien pregunta que ese recurso SÍ está aquí, que es
         * justo lo que el Doc 06 no permite filtrar.
         */
        $respuesta->assertNotFound();
        $respuesta->assertJsonPath(
            'detail',
            'El recurso solicitado no existe o no está a tu alcance.'
        );
    }

    public function test_una_peticion_sin_token_no_dice_si_el_endpoint_tiene_datos(): void
    {
        $respuesta = $this->getJson('/api/v1/unidades');

        $respuesta->assertUnauthorized();
        $respuesta->assertHeader('Content-Type', 'application/problem+json');
        $respuesta->assertJsonPath('title', 'No autenticado');
    }

    public function test_la_web_no_responde_problem_json(): void
    {
        $respuesta = $this->get('/no-existe');

        $respuesta->assertNotFound();
        $this->assertStringNotContainsString(
            'application/problem+json',
            (string) $respuesta->headers->get('Content-Type')
        );
    }
}
