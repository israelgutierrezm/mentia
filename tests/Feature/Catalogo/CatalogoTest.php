<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Domain\Catalogo\Excepciones\HabilitacionInvalida;
use App\Domain\Catalogo\Excepciones\VersionInmutable;
use App\Domain\Catalogo\Modelos\ClaveCalificacion;
use App\Domain\Catalogo\Modelos\FormulaDerivada;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\TipoReactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Catalogo\Servicios\GestorTenantInstrumentos;
use App\Domain\Catalogo\Servicios\PublicadorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Apoyo\EscenarioTenant;
use Tests\Apoyo\InstrumentoSintetico;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function publicador(): PublicadorVersion
    {
        return app(PublicadorVersion::class);
    }

    private function habilitacion(): GestorTenantInstrumentos
    {
        return app(GestorTenantInstrumentos::class);
    }

    // ── El catálogo describe cualquier instrumento sin tocar código ───────

    public function test_el_catalogo_soporta_todos_los_tipos_de_reactivo(): void
    {
        $sintetico = InstrumentoSintetico::conTodosLosTiposDeReactivo();

        $tipos = TipoReactivo::query()->count();

        $this->assertGreaterThanOrEqual(16, $tipos);

        $usados = Reactivo::query()
            ->where('version_instrumento_id', $sintetico->version->id)
            ->distinct()
            ->count('tipo_reactivo_id');

        /*
         * Si el catálogo puede describir un instrumento que usa los dieciséis
         * tipos, puede describir cualquiera de la Ola 1 sin código nuevo
         * (principio P3).
         */
        $this->assertSame($tipos, $usados);

        $this->publicador()->publicar($sintetico->version);

        $this->assertTrue($sintetico->version->refresh()->estaPublicada());
    }

    public function test_los_tipos_sin_opciones_no_las_exigen(): void
    {
        $sintetico = new InstrumentoSintetico('abiertos');
        $sintetico->escala('E1');

        $abierto = $sintetico->reactivo('texto_abierto', 'E1');

        $this->assertSame(0, $abierto->opciones()->count());
        $this->assertSame(
            1,
            ClaveCalificacion::query()->where('reactivo_id', $abierto->id)->count(),
            'Sin opciones, la clave cuelga del reactivo completo.'
        );
    }

    // ── Inmutabilidad tras publicar ───────────────────────────────────────

    public function test_una_version_publicada_no_admite_escritura_de_contenido(): void
    {
        $sintetico = new InstrumentoSintetico('inmutable');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');

        $this->publicador()->publicar($sintetico->version);

        $this->expectException(VersionInmutable::class);

        /*
         * La comprobación que TODO servicio de contenido debe hacer. Una
         * aplicación de hace dos años apunta a esta versión exacta: si su
         * contenido cambiara, su resultado dejaría de ser reproducible.
         */
        $this->publicador()->exigirEditable($sintetico->version->refresh());
    }

    public function test_una_version_retirada_tampoco_admite_escritura(): void
    {
        $sintetico = new InstrumentoSintetico('retirada');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');

        $this->publicador()->publicar($sintetico->version);
        $this->publicador()->retirar($sintetico->version->refresh());

        $this->expectException(VersionInmutable::class);

        $this->publicador()->exigirEditable($sintetico->version->refresh());
    }

    public function test_no_se_publica_una_version_sin_reactivos(): void
    {
        $sintetico = new InstrumentoSintetico('vacio');
        $sintetico->escala('E1');

        $this->expectException(VersionInmutable::class);

        // Publicar una versión vacía dejaría un instrumento asignable que
        // nadie puede contestar.
        $this->publicador()->publicar($sintetico->version);
    }

    public function test_no_se_publica_con_una_escala_que_nunca_puntua(): void
    {
        $sintetico = new InstrumentoSintetico('escala_muerta');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');

        // Una escala sin ninguna clave: al aplicarse siempre daría cero y
        // nadie sabría por qué.
        $sintetico->escala('HUERFANA');

        $this->expectException(VersionInmutable::class);

        $this->publicador()->publicar($sintetico->version);
    }

    public function test_una_formula_que_cita_una_escala_inexistente_no_publica(): void
    {
        $sintetico = new InstrumentoSintetico('formula_mala');
        $sintetico->escala('M');
        $sintetico->reactivo('likert_5', 'M');
        $derivada = $sintetico->escala('T');

        FormulaDerivada::query()->create([
            'version_instrumento_id' => $sintetico->version->id,
            'escala_destino_id' => $derivada->id,
            'expresion' => 'M - L',
            'orden_evaluacion' => 1,
        ]);

        $this->expectException(VersionInmutable::class);

        // `L` no existe en esta versión. Se detecta AQUÍ y no al calificar,
        // que es cuando ya hay alguien esperando su resultado.
        $this->publicador()->publicar($sintetico->version);
    }

    public function test_una_formula_con_llamada_a_funcion_no_publica(): void
    {
        $sintetico = new InstrumentoSintetico('formula_peligrosa');
        $sintetico->escala('M');
        $sintetico->reactivo('likert_5', 'M');
        $derivada = $sintetico->escala('T');

        FormulaDerivada::query()->create([
            'version_instrumento_id' => $sintetico->version->id,
            'escala_destino_id' => $derivada->id,
            'expresion' => 'system("rm -rf /")',
            'orden_evaluacion' => 1,
        ]);

        $this->expectException(VersionInmutable::class);

        /*
         * Las expresiones llegan de una hoja de Excel que sube un tenant. Si
         * se evaluaran con eval(), esto sería ejecución remota de código.
         */
        $this->publicador()->publicar($sintetico->version);
    }

    public function test_corregir_una_version_publicada_es_clonarla(): void
    {
        $sintetico = new InstrumentoSintetico('clonable');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $sintetico->reactivo('dicotomico', 'E1');

        $this->publicador()->publicar($sintetico->version);

        $nueva = $this->publicador()->nuevaVersionDesde(
            $sintetico->version->refresh(),
            '1.1',
            'Corrección de erratas.'
        );

        $this->assertSame(VersionInstrumento::BORRADOR, $nueva->estado);

        // Contenido completo, listo para corregirse.
        $this->assertSame(2, $nueva->reactivos()->count());
        $this->assertSame(1, $nueva->escalas()->count());
        $this->assertGreaterThan(0, $nueva->claves()->count());

        // Y la publicada sigue intacta: es lo que hace reproducible una
        // aplicación de hace dos años.
        $this->assertSame(2, $sintetico->version->refresh()->reactivos()->count());
    }

    // ── Habilitación por licencia ─────────────────────────────────────────

    public function test_un_instrumento_de_dominio_publico_se_habilita_directo(): void
    {
        EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('dp');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $this->publicador()->publicar($sintetico->version);

        $registro = $this->habilitacion()->habilitarDominioPublico($sintetico->version->refresh());

        $this->assertSame(TenantInstrumento::HABILITADO, $registro->estado);
        $this->assertTrue($registro->sePuedeAsignar());
    }

    public function test_un_instrumento_con_copyright_no_se_habilita_directo(): void
    {
        EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('lic', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $this->publicador()->publicar($sintetico->version);

        $this->expectException(HabilitacionInvalida::class);

        $this->habilitacion()->habilitarDominioPublico($sintetico->version->refresh());
    }

    public function test_la_declaracion_de_licencia_deja_pendiente_de_contenido(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $firmante = $escenario->persona();

        $sintetico = new InstrumentoSintetico('lic2', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $this->publicador()->publicar($sintetico->version);

        $registro = $this->habilitacion()->declararLicencia(
            $sintetico->version->refresh(),
            $firmante,
            'Declaro contar con licencia vigente del editor para aplicar este instrumento.'
        );

        /*
         * Pendiente, NO habilitado: declarar la licencia no pone los
         * reactivos. Habilitarlo aquí dejaría asignable una prueba vacía.
         */
        $this->assertSame(TenantInstrumento::PENDIENTE_CONTENIDO, $registro->estado);
        $this->assertFalse($registro->sePuedeAsignar());

        // La cadena de responsabilidad: el texto, quién y cuándo.
        $this->assertNotNull($registro->declaracion_licencia_texto);
        $this->assertSame($firmante->id, $registro->declaracion_firmada_por);
        $this->assertNotNull($registro->declaracion_firmada_en);
    }

    public function test_una_declaracion_vacia_se_rechaza(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('lic3', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $this->publicador()->publicar($sintetico->version);

        $this->expectException(HabilitacionInvalida::class);

        // Ante una reclamación editorial, "marcó una casilla" no es defensa.
        $this->habilitacion()->declararLicencia(
            $sintetico->version->refresh(),
            $escenario->persona(),
            '   '
        );
    }

    public function test_no_se_habilita_sin_haber_capturado_contenido(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('lic4', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $this->publicador()->publicar($sintetico->version);

        $registro = $this->habilitacion()->declararLicencia(
            $sintetico->version->refresh(),
            $escenario->persona(),
            'Declaro contar con licencia.'
        );

        $this->expectException(HabilitacionInvalida::class);

        $this->habilitacion()->habilitarTrasCapturarContenido($registro);
    }

    public function test_un_borrador_no_se_habilita(): void
    {
        EscenarioTenant::nuevo()->activar();

        $sintetico = new InstrumentoSintetico('borrador');
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');

        $this->expectException(HabilitacionInvalida::class);

        // Su contenido todavía puede cambiar: una aplicación contra un
        // borrador no sería reproducible.
        $this->habilitacion()->habilitarDominioPublico($sintetico->version);
    }

    // ── Contenido privado del tenant ──────────────────────────────────────

    public function test_el_contenido_capturado_por_un_tenant_jamas_se_sirve_a_otro(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $sintetico = new InstrumentoSintetico('esqueleto', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');

        // Reactivo global del esqueleto.
        $sintetico->reactivo('likert_5', 'E1');

        // Y el que A capturó bajo SU licencia.
        $privadoDeA = $sintetico->reactivo(
            'likert_5',
            'E1',
            organizacionIdContenido: $a->organizacion->id
        );

        $visiblesParaB = Reactivo::query()
            ->where('version_instrumento_id', $sintetico->version->id)
            ->deContenidoVisiblePara($b->organizacion->id)
            ->pluck('id')
            ->all();

        /*
         * B ve el esqueleto global y NADA de lo que A capturó. No es una
         * preferencia de producto: es la cadena de responsabilidad ante la
         * editorial (Doc 06 §3). Servirle a B el contenido que A licenció
         * sería distribuir material con copyright sin licencia.
         */
        $this->assertNotContains($privadoDeA->id, $visiblesParaB);
        $this->assertCount(1, $visiblesParaB);

        // A sí ve los dos: el global y el suyo.
        $visiblesParaA = Reactivo::query()
            ->where('version_instrumento_id', $sintetico->version->id)
            ->deContenidoVisiblePara($a->organizacion->id)
            ->pluck('id')
            ->all();

        $this->assertContains($privadoDeA->id, $visiblesParaA);
        $this->assertCount(2, $visiblesParaA);
    }

    public function test_el_clon_conserva_el_ambito_del_contenido_privado(): void
    {
        $a = EscenarioTenant::nuevo();

        $sintetico = new InstrumentoSintetico('esqueleto2', Instrumento::REQUIERE_LICENCIA);
        $sintetico->escala('E1');
        $sintetico->reactivo('likert_5', 'E1');
        $sintetico->reactivo('likert_5', 'E1', organizacionIdContenido: $a->organizacion->id);

        $this->publicador()->publicar($sintetico->version);
        $nueva = $this->publicador()->nuevaVersionDesde($sintetico->version->refresh(), '1.1');

        $privados = Reactivo::query()
            ->where('version_instrumento_id', $nueva->id)
            ->whereNotNull('organizacion_id_contenido')
            ->pluck('organizacion_id_contenido')
            ->all();

        $this->assertSame(
            [$a->organizacion->id],
            $privados,
            'Lo que era privado de un tenant sigue siéndolo en la versión nueva.'
        );
    }

    public function test_el_catalogo_del_tenant_no_muestra_instrumentos_propios_de_otro(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        new InstrumentoSintetico('global_uno');
        new InstrumentoSintetico('propio_de_a', organizacionId: $a->organizacion->id);

        $visiblesParaB = Instrumento::query()
            ->visiblesPara($b->organizacion->id)
            ->pluck('clave')
            ->all();

        $this->assertContains('global_uno', $visiblesParaB);
        $this->assertNotContains('propio_de_a', $visiblesParaB);
    }
}
