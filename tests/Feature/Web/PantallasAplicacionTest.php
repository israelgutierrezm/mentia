<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domain\Evaluaciones\Datos\Invitacion;
use App\Domain\Evaluaciones\Servicios\GestorTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Apoyo\EscenarioAplicacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Las pantallas de la Fase 6: la pública de contestar y la de captura.
 */
class PantallasAplicacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_contestar_es_publica_y_no_pide_sesion(): void
    {
        /*
         * Es la única pantalla del sistema sin sesión. La madre que recibe la
         * liga para responder el M-CHAT sobre su hijo no tiene cuenta en nada;
         * si esta ruta exigiera autenticación, no habría forma de contestar.
         */
        $this->get('/contestar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina->component('Aplicacion/Canje'));
    }

    public function test_la_ruta_de_contestar_no_acepta_el_token_en_la_ruta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);

        $token = app(GestorTokens::class)->generar($escenario->destinatario);

        /*
         * Que esto sea 404 es el punto, no un descuido. Una ruta
         * `/contestar/{token}` sería lo cómodo y escribiría la credencial de
         * quien contesta en el log de accesos del servidor web, en el proxy
         * corporativo y en el `Referer` de cualquier liga que salga de la
         * página. El token entra por el fragmento y se canjea por POST.
         */
        $this->get('/contestar/'.$token)->assertNotFound();
    }

    public function test_la_liga_de_invitacion_lleva_el_token_en_el_fragmento(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAplicacion($tenant);

        $token = app(GestorTokens::class)->generar($escenario->destinatario);
        $liga = Invitacion::para($escenario->destinatario, $token)->liga();

        $this->assertStringContainsString('/contestar#'.$token, $liga);

        /*
         * Con el token en la RUTA, la credencial de quien contesta un tamizaje
         * clínico se escribe en el log de accesos del servidor web y en cada
         * proxy por el que pase la petición.
         */
        $this->assertStringNotContainsString('/contestar/'.$token, $liga);
    }

    public function test_la_captura_de_protocolo_exige_su_permiso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $escenario->asignarRol($persona, $escenario->rol('Docente', ['personas.ver'], 1));

        $this->actingAs($escenario->usuarioDe($persona))
            ->withSession(['organizacion_id' => $escenario->organizacion->id])
            ->get('/captura-protocolo')
            ->assertForbidden();
    }

    public function test_la_captura_de_protocolo_abre_con_el_permiso(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $escenario->asignarRol(
            $persona,
            $escenario->rol('Psicóloga', ['protocolos.capturar', 'personas.ver'], 4)
        );

        $this->actingAs($escenario->usuarioDe($persona))
            ->withSession(['organizacion_id' => $escenario->organizacion->id])
            ->get('/captura-protocolo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Aplicacion/CapturaProtocolo')
                ->has('instrumentos'));
    }
}
