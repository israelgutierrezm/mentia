<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domain\Accesos\CatalogoPermisos;
use App\Domain\Accesos\CatalogoSecciones;
use App\Domain\Accesos\Datos\Seccion;
use App\Domain\Alertas\Modelos\Alerta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * El panel y el menú, armados por tarjetas declaradas con su permiso.
 *
 * Lo que se prueba es que NO haya ramas por rol: la misma URL le enseña a cada
 * quien lo que alcanza, y lo que no alcanza no sale del servidor.
 */
class PanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_toda_seccion_declara_un_permiso_que_existe(): void
    {
        $declarados = array_map(
            static fn ($permiso): string => $permiso->clave,
            (new CatalogoPermisos)->todos(),
        );

        foreach ((new CatalogoSecciones)->todas() as $seccion) {
            if ($seccion->permiso === null) {
                continue;
            }

            /*
             * Un permiso mal escrito escondería la sección para siempre y nadie
             * se enteraría: la pantalla existiría, la ruta funcionaría, y el
             * menú simplemente no la mostraría nunca. Se caza aquí y no en
             * producción.
             */
            $this->assertContains(
                $seccion->permiso,
                $declarados,
                sprintf('La sección «%s» declara un permiso inexistente.', $seccion->clave),
            );
        }
    }

    public function test_el_menu_solo_trae_lo_que_el_rol_alcanza(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $docente = $tenant->persona();
        $tenant->asignarRol($docente, $tenant->rol('Docente', ['personas.ver'], 1));

        $respuesta = $this->actingAs($tenant->usuarioDe($docente))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/');

        $respuesta->assertOk();

        $claves = $this->clavesDelMenu($respuesta);

        $this->assertContains('personas', $claves);
        $this->assertContains('catalogo', $claves, 'El catálogo no exige permiso.');

        /*
         * Mandar la lista completa y esconder con `v-if` en el cliente sería
         * decirle al navegador qué existe: cualquiera abriría las herramientas
         * de desarrollo para ver el mapa de un sistema al que no tiene acceso.
         */
        $this->assertNotContains('roles', $claves);
        $this->assertNotContains('alertas', $claves);
    }

    public function test_un_rol_de_nivel_alto_ve_mas_secciones(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $coordinadora = $tenant->persona();
        $tenant->asignarRol($coordinadora, $tenant->rol('Coordinadora', [
            'personas.ver', 'roles.gestionar', 'alertas.atender', 'baterias.armar',
        ], 4));

        $respuesta = $this->actingAs($tenant->usuarioDe($coordinadora))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/');

        $claves = $this->clavesDelMenu($respuesta);

        $this->assertContains('roles', $claves);
        $this->assertContains('alertas', $claves);
        $this->assertContains('baterias', $claves);
    }

    public function test_el_panel_pone_arriba_las_alertas_criticas_abiertas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $psicologa = $tenant->persona();
        $tenant->asignarRol($psicologa, $tenant->rol('Psicóloga', [
            'alertas.atender', 'personas.ver',
        ], 4));

        Alerta::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'tipo' => 'centinela',
            'severidad' => 'critica',
            'mensaje' => 'Riesgo detectado.',
            'estado' => 'nueva',
            'creada_en' => Carbon::now(),
        ]);

        $this->actingAs($tenant->usuarioDe($psicologa))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Panel')
                ->has('pendientes', 1)
                ->where('pendientes.0.urgente', true));
    }

    public function test_quien_no_atiende_alertas_no_ve_su_conteo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $docente = $tenant->persona();
        $tenant->asignarRol($docente, $tenant->rol('Docente', ['personas.ver'], 1));

        Alerta::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'tipo' => 'centinela',
            'severidad' => 'critica',
            'mensaje' => 'Riesgo detectado.',
            'estado' => 'nueva',
            'creada_en' => Carbon::now(),
        ]);

        // Saber cuántas alertas hay abiertas ya es información clínica sobre el
        // grupo: quien no las atiende no tiene por qué enterarse.
        $this->actingAs($tenant->usuarioDe($docente))
            ->withSession(['organizacion_id' => $tenant->organizacion->id])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina->has('pendientes', 0));
    }

    public function test_el_panel_exige_sesion_y_organizacion(): void
    {
        $this->get('/')->assertRedirect();
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $respuesta
     * @return list<string>
     */
    private function clavesDelMenu(TestResponse $respuesta): array
    {
        /** @var array{props: array{menu?: list<array{clave: string}>}} $pagina */
        $pagina = $respuesta->viewData('page');

        return array_map(
            static fn (array $seccion): string => $seccion['clave'],
            $pagina['props']['menu'] ?? [],
        );
    }

    public function test_las_secciones_no_repiten_clave(): void
    {
        $claves = array_map(
            static fn (Seccion $seccion): string => $seccion->clave,
            (new CatalogoSecciones)->todas(),
        );

        $this->assertSame(count($claves), count(array_unique($claves)));
    }
}
