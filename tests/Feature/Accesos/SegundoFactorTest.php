<?php

declare(strict_types=1);

namespace Tests\Feature\Accesos;

use App\Domain\Accesos\Servicios\ExigenciaDeSegundoFactor;
use App\Domain\Accesos\Servicios\SegundoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * 2FA obligatoria para roles de sensibilidad 3–4 (Doc 06 §4).
 *
 * Quien puede abrir el expediente clínico de un menor entra con dos factores.
 * No es una preferencia del usuario: es un requisito del ROL, y por eso el
 * sistema BLOQUEA en vez de sugerir.
 */
class SegundoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_un_rol_de_nivel_alto_queda_obligado(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', ['personas.ver'], 4));

        $this->assertTrue(app(ExigenciaDeSegundoFactor::class)->obligatorioPara($psicologa));
    }

    public function test_un_rol_de_nivel_bajo_no(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $docente = $tenant->persona();
        $tenant->asignarRol($docente, $tenant->rol('Docente', ['personas.ver'], 1));

        /*
         * Exigírselo a todo el mundo suena más seguro y no lo es: un docente
         * que sólo ve nombres de su grupo abandona el sistema antes de terminar
         * de configurar una aplicación de autenticación, y entonces alguien le
         * pasa su contraseña a otro.
         */
        $this->assertFalse(app(ExigenciaDeSegundoFactor::class)->obligatorioPara($docente));
    }

    public function test_el_bloqueo_manda_a_activarlo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', ['personas.ver'], 4));

        // Una cuenta SIN segundo factor, a diferencia de las del andamio.
        $cuenta = User::factory()->de($psicologa)->create();

        $this->actingAs($cuenta)
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/personas')
            ->assertRedirect('/seguridad/dos-factores');
    }

    public function test_la_pantalla_de_activacion_sigue_abierta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', ['personas.ver'], 4));

        $cuenta = User::factory()->de($psicologa)->create();

        /*
         * Encerrar a la persona sin dejarle activar lo que se le exige
         * convertiría la medida en un candado sin llave.
         */
        $this->actingAs($cuenta)
            ->get('/seguridad/dos-factores')
            ->assertOk();
    }

    public function test_la_api_responde_403_en_vez_de_redirigir(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        $cuenta = User::factory()->de($psicologa)->create();

        // Una redirección a HTML dentro de una respuesta que la app Flutter va
        // a pasar por un parser de JSON no le dice nada a quien depura.
        $this->actingAs($cuenta)
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->getJson('/api/v1/alertas', ['X-Organizacion' => (string) $tenant->organizacion->id])
            ->assertForbidden();
    }

    public function test_confirmar_con_un_codigo_valido_entrega_los_de_recuperacion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create();

        $servicio = app(SegundoFactor::class);
        $secreto = $servicio->preparar($cuenta);

        $codigo = (new Google2FA)->getCurrentOtp($secreto);
        $codigos = $servicio->confirmar($cuenta->refresh(), $codigo);

        $this->assertCount(8, $codigos);
        $this->assertNotNull($cuenta->refresh()->dos_factores_confirmado_en);
    }

    public function test_un_codigo_de_recuperacion_es_de_un_solo_uso(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create();

        $servicio = app(SegundoFactor::class);
        $secreto = $servicio->preparar($cuenta);
        $codigos = $servicio->confirmar($cuenta->refresh(), (new Google2FA)->getCurrentOtp($secreto));

        $this->assertTrue($servicio->verificar($cuenta->refresh(), $codigos[0]));

        /*
         * Dejarlos reutilizables convertiría una captura de pantalla en una
         * llave permanente.
         */
        $this->assertFalse($servicio->verificar($cuenta->refresh(), $codigos[0]));

        // Los demás siguen sirviendo.
        $this->assertTrue($servicio->verificar($cuenta->refresh(), $codigos[1]));
    }

    public function test_un_codigo_equivocado_no_confirma_nada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create();

        $servicio = app(SegundoFactor::class);
        $servicio->preparar($cuenta);

        $this->expectException(RuntimeException::class);

        $servicio->confirmar($cuenta->refresh(), '000000');
    }

    public function test_el_secreto_se_guarda_cifrado(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create();

        $secreto = app(SegundoFactor::class)->preparar($cuenta);

        $guardado = (string) \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $cuenta->id)
            ->value('dos_factores_secreto');

        /*
         * Quien lea un respaldo de la base no debe poder generar los códigos de
         * nadie.
         */
        $this->assertNotSame($secreto, $guardado);
        $this->assertStringNotContainsString($secreto, $guardado);
    }
}
