<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Entrar y salir.
 *
 * La ruta de login no existía: `admin-base` la iba a traer y admin-base nunca
 * existió (ver `docs/decisiones.md`). Sin ella el middleware `auth` redirigía a
 * una ruta inexistente y nadie podía usar el sistema.
 */
class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_la_pantalla_de_entrar_es_publica(): void
    {
        $this->get('/entrar')->assertOk();
    }

    public function test_entrar_deja_activa_la_primera_organizacion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $persona = $tenant->persona();

        $cuenta = User::factory()->de($persona)->create([
            'password' => Hash::make('contrasena-de-prueba'),
        ]);

        $respuesta = $this->post('/entrar', [
            'email' => $cuenta->email,
            'password' => 'contrasena-de-prueba',
        ]);

        $respuesta->assertRedirect('/');
        $this->assertAuthenticatedAs($cuenta);

        /*
         * Sin organización activa los global scopes fallan cerrado y todas las
         * pantallas salen vacías: quien entra y ve un sistema en blanco
         * concluye que está roto, no que le falta elegir tenant.
         */
        $this->assertSame(
            $tenant->organizacion->id,
            session('organizacion_id'),
        );
    }

    public function test_una_contrasena_equivocada_no_dice_si_el_correo_existe(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = User::factory()->de($tenant->persona())->create([
            'password' => Hash::make('la-buena'),
        ]);

        $this->post('/entrar', ['email' => $cuenta->email, 'password' => 'la-mala']);
        $conCorreoReal = (string) session('errors')->first('email');

        $this->flushSession();

        $this->post('/entrar', ['email' => 'nadie@ejemplo.mx', 'password' => 'la-mala']);
        $conCorreoInventado = (string) session('errors')->first('email');

        /*
         * El MISMO mensaje. Distinguirlos convierte el formulario en un
         * verificador de qué correos tienen cuenta aquí, y tener cuenta aquí ya
         * dice algo de una persona.
         */
        $this->assertSame($conCorreoReal, $conCorreoInventado);

        $this->assertGuest();
    }

    public function test_los_intentos_se_estrangulan(): void
    {
        RateLimiter::clear('entrar:objetivo@ejemplo.mx|127.0.0.1');

        foreach (range(1, 5) as $ignorado) {
            $this->post('/entrar', [
                'email' => 'objetivo@ejemplo.mx',
                'password' => 'probando',
            ]);
        }

        $this->post('/entrar', ['email' => 'objetivo@ejemplo.mx', 'password' => 'probando']);

        $this->assertStringContainsString(
            'Demasiados intentos',
            (string) session('errors')->first('email'),
        );
    }

    public function test_salir_cierra_la_sesion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $cuenta = $tenant->usuarioDe($tenant->persona());

        $this->actingAs($cuenta)
            ->post('/salir')
            ->assertRedirect('/entrar');

        $this->assertGuest();
    }

    public function test_no_hay_registro_publico(): void
    {
        /*
         * Alguien que se registra solo no está vinculado a ningún tenant y no
         * puede ver nada, pero sí habría creado una cuenta con un correo que
         * quizá no es suyo, en un sistema que guarda expedientes clínicos.
         */
        $this->post('/registro', [
            'email' => 'intruso@ejemplo.mx',
            'password' => 'lo-que-sea',
        ])->assertNotFound();
    }
}
