<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluaciones;

use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Servicios\GestorTenantInstrumentos;
use App\Domain\Catalogo\Servicios\PublicadorVersion;
use App\Domain\Evaluaciones\Excepciones\BateriaInvalida;
use App\Domain\Evaluaciones\Modelos\BateriaInstrumento;
use App\Domain\Evaluaciones\Servicios\GestorBaterias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\Apoyo\InstrumentoSintetico;
use Tests\TestCase;

class BateriasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function gestor(): GestorBaterias
    {
        return app(GestorBaterias::class);
    }

    /**
     * Un instrumento publicado y HABILITADO para la organización activa.
     */
    private function habilitado(string $clave): InstrumentoSintetico
    {
        $sintetico = new InstrumentoSintetico($clave);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');

        app(PublicadorVersion::class)->publicar($sintetico->version);
        app(GestorTenantInstrumentos::class)
            ->habilitarDominioPublico($sintetico->version->refresh());

        return $sintetico;
    }

    public function test_se_arma_una_bateria_y_se_activa(): void
    {
        EscenarioTenant::nuevo()->activar();

        $uno = $this->habilitado('uno');
        $dos = $this->habilitado('dos');

        $bateria = $this->gestor()->crear('seleccion', 'Selección mando medio');

        $this->gestor()->agregar($bateria, $uno->version);
        $this->gestor()->agregar($bateria, $dos->version);

        $this->assertSame(2, $bateria->instrumentos()->count());

        $this->gestor()->activar($bateria);

        $this->assertSame('activa', $bateria->refresh()->estado);
    }

    public function test_no_se_activa_una_bateria_vacia(): void
    {
        EscenarioTenant::nuevo()->activar();

        $bateria = $this->gestor()->crear('vacia', 'Vacía');

        $this->expectException(BateriaInvalida::class);

        // Quien la reciba no tendría nada que contestar.
        $this->gestor()->activar($bateria);
    }

    public function test_no_se_agrega_un_instrumento_no_habilitado(): void
    {
        EscenarioTenant::nuevo()->activar();

        // Publicado pero SIN habilitar para esta organización.
        $sintetico = new InstrumentoSintetico('sin_habilitar');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        app(PublicadorVersion::class)->publicar($sintetico->version);

        $bateria = $this->gestor()->crear('b', 'Batería');

        $this->expectException(BateriaInvalida::class);

        /*
         * Una batería con un instrumento apagado se arma sin protestar y
         * revienta al asignarla, delante de la persona que iba a contestarla.
         */
        $this->gestor()->agregar($bateria, $sintetico->version->refresh());
    }

    public function test_no_se_agrega_un_instrumento_de_solo_captura(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('cap', Instrumento::SOLO_CAPTURA, modoCalificacion: 'captura_protocolo');
        $sintetico->escala('E1');
        app(PublicadorVersion::class)->publicar($sintetico->version);

        // Se fuerza la habilitación para aislar la regla que se prueba.
        TenantInstrumento::query()->create([
            'organizacion_id' => $tenant->organizacion->id,
            'version_instrumento_id' => $sintetico->version->id,
            'estado' => TenantInstrumento::HABILITADO,
        ]);

        $bateria = $this->gestor()->crear('b2', 'Batería');

        $this->expectException(BateriaInvalida::class);

        // La batería se contesta en línea; éste la editorial no lo permite.
        $this->gestor()->agregar($bateria, $sintetico->version->refresh());
    }

    public function test_reordenar_guarda_el_orden_completo(): void
    {
        EscenarioTenant::nuevo()->activar();

        $bateria = $this->gestor()->crear('orden', 'Con orden');

        $primero = $this->gestor()->agregar($bateria, $this->habilitado('a')->version);
        $segundo = $this->gestor()->agregar($bateria, $this->habilitado('b')->version);
        $tercero = $this->gestor()->agregar($bateria, $this->habilitado('c')->version);

        $this->assertSame([1, 2, 3], [$primero->orden, $segundo->orden, $tercero->orden]);

        // Se arrastra el tercero al principio.
        $this->gestor()->reordenar($bateria, [$tercero->id, $primero->id, $segundo->id]);

        $this->assertSame(1, $tercero->refresh()->orden);
        $this->assertSame(2, $primero->refresh()->orden);
        $this->assertSame(3, $segundo->refresh()->orden);
    }

    public function test_un_orden_parcial_se_rechaza(): void
    {
        EscenarioTenant::nuevo()->activar();

        $bateria = $this->gestor()->crear('parcial', 'Parcial');
        $primero = $this->gestor()->agregar($bateria, $this->habilitado('x')->version);
        $this->gestor()->agregar($bateria, $this->habilitado('y')->version);

        $this->expectException(BateriaInvalida::class);

        /*
         * Una lista parcial dejaría renglones con orden viejo mezclados con
         * los nuevos, y el resultado sería un orden que nadie pidió.
         */
        $this->gestor()->reordenar($bateria, [$primero->id]);
    }

    public function test_no_se_reordena_una_bateria_con_asignaciones_activas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $bateria = $this->gestor()->crear('en_uso', 'En uso');
        $uno = $this->gestor()->agregar($bateria, $this->habilitado('p')->version);
        $dos = $this->gestor()->agregar($bateria, $this->habilitado('q')->version);
        $this->gestor()->activar($bateria);

        app(\App\Domain\Evaluaciones\Servicios\CreadorAsignaciones::class)->crear(
            proposito: $escenario->proposito,
            autor: $tenant->persona(),
            origenTipo: 'individual',
            destinatariosUuid: [$tenant->persona()->uuid],
            versionInstrumentoId: null,
            bateriaId: $bateria->id,
        );

        $this->expectException(BateriaInvalida::class);

        /*
         * Cambiar el orden a media campaña haría que dos personas contestaran
         * la misma batería en secuencias distintas, y el orden afecta al
         * resultado por fatiga y aprendizaje entre instrumentos.
         */
        $this->gestor()->reordenar($bateria, [$dos->id, $uno->id]);
    }

    public function test_una_bateria_de_otro_tenant_no_se_toca(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $b->activar();
        $deB = $this->gestor()->crear('de_b', 'De B');

        $a->activar();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->gestor()->actualizar($deB, ['nombre' => 'Secuestrada']);
    }

    public function test_las_versiones_disponibles_son_las_habilitadas(): void
    {
        EscenarioTenant::nuevo()->activar();

        $habilitado = $this->habilitado('disponible');

        $suelto = new InstrumentoSintetico('no_disponible');
        $suelto->escala('E1');
        $suelto->reactivo('likert_5', 'E1');
        app(PublicadorVersion::class)->publicar($suelto->version);

        $ids = $this->gestor()->versionesDisponibles()->pluck('id')->all();

        $this->assertContains($habilitado->version->id, $ids);
        $this->assertNotContains($suelto->version->id, $ids);
    }

    public function test_quitar_un_renglon_de_otra_bateria_responde_404(): void
    {
        EscenarioTenant::nuevo()->activar();

        $primera = $this->gestor()->crear('b_uno', 'Uno');
        $segunda = $this->gestor()->crear('b_dos', 'Dos');

        $ajeno = $this->gestor()->agregar($segunda, $this->habilitado('z')->version);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->gestor()->quitar($primera, $ajeno);
    }

    public function test_agregar_dos_veces_el_mismo_instrumento_no_duplica(): void
    {
        EscenarioTenant::nuevo()->activar();

        $bateria = $this->gestor()->crear('idem', 'Idempotente');
        $version = $this->habilitado('unico')->version;

        $this->gestor()->agregar($bateria, $version);
        $this->gestor()->agregar($bateria, $version);

        $this->assertSame(
            1,
            BateriaInstrumento::query()->where('bateria_id', $bateria->id)->count()
        );
    }
}
