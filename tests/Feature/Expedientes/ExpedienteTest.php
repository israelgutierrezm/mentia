<?php

declare(strict_types=1);

namespace Tests\Feature\Expedientes;

use App\Domain\Expedientes\Excepciones\CapturaNoPermitida;
use App\Domain\Expedientes\Modelos\ExpedienteCampo;
use App\Domain\Expedientes\Modelos\NotaProfesional;
use App\Domain\Expedientes\Servicios\CapturaExpediente;
use App\Domain\Expedientes\Servicios\VistaExpediente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

class ExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function captura(): CapturaExpediente
    {
        return app(CapturaExpediente::class);
    }

    private function campo(string $clave): ExpedienteCampo
    {
        return ExpedienteCampo::query()->where('clave', $clave)->firstOrFail();
    }

    // ── Versionado ────────────────────────────────────────────────────────

    public function test_corregir_un_dato_no_lo_pisa_versiona(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();

        $campo = $this->campo('telefono');

        $primero = $this->captura()->capturar($persona, $campo, '5512345678', $persona);
        $segundo = $this->captura()->capturar($persona, $campo, '5599998888', $persona);

        $this->assertSame(1, $primero->version);
        $this->assertSame(2, $segundo->version);

        /*
         * Las dos filas siguen ahí. Es lo que hace posible la rectificación
         * ARCO sin destruir el dato anterior (Doc 06 §3): se puede demostrar
         * qué decía antes y quién lo cambió.
         */
        $this->assertSame(
            2,
            DB::table('expediente_valores')
                ->where('campo_id', $campo->id)
                ->count()
        );
    }

    public function test_lo_capturado_por_el_titular_no_es_vigente_hasta_validarse(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();

        $campo = $this->campo('telefono');

        $pendiente = $this->captura()->capturar($persona, $campo, '5500000000', $persona);

        $this->assertSame('pendiente_validacion', $pendiente->estado);

        $this->assertNull(
            $this->captura()->valorVigenteDe($this->captura()->expedienteDe($persona), $campo),
            'Los datos los aporta la persona, pero quien responde de ellos ante la '
            .'organización es un profesional.'
        );
    }

    public function test_el_vigente_es_la_mayor_version_validada(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();
        $profesional = $escenario->persona();

        $campo = $this->campo('telefono');

        $primero = $this->captura()->capturar($persona, $campo, '5500000000', $persona);
        $this->captura()->validar($primero, $profesional);

        $segundo = $this->captura()->capturar($persona, $campo, '5511112222', $persona);
        $this->captura()->validar($segundo, $profesional);

        $vigente = $this->captura()->valorVigenteDe(
            $this->captura()->expedienteDe($persona),
            $campo
        );

        $this->assertNotNull($vigente);
        $this->assertSame(2, $vigente->version);
        $this->assertSame(
            '5511112222',
            $vigente->contenido(),
            'El vigente es la mayor versión validada, no la primera ni la última capturada.'
        );
    }

    public function test_una_version_mas_nueva_sin_validar_no_desplaza_a_la_vigente(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();
        $profesional = $escenario->persona();

        $campo = $this->campo('telefono');

        $validado = $this->captura()->capturar($persona, $campo, '5500000000', $persona);
        $this->captura()->validar($validado, $profesional);

        // El titular manda una corrección que todavía nadie revisó.
        $this->captura()->capturar($persona, $campo, '5599999999', $persona);

        $vigente = $this->captura()->valorVigenteDe(
            $this->captura()->expedienteDe($persona),
            $campo
        );

        $this->assertNotNull($vigente);
        $this->assertSame(
            '5500000000',
            $vigente->contenido(),
            'Una corrección sin validar no puede desplazar al dato que la organización '
            .'ya dio por bueno.'
        );
    }

    public function test_lo_que_captura_un_profesional_nace_validado(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();
        $profesional = $escenario->persona();

        // `antecedentes` está marcado `profesional`.
        $valor = $this->captura()->capturar(
            $persona,
            $this->campo('antecedentes'),
            'Sin antecedentes relevantes.',
            $profesional
        );

        $this->assertSame(
            'validado',
            $valor->estado,
            'Pedirle a un profesional que valide lo suyo sería un trámite vacío.'
        );
    }

    public function test_el_titular_no_captura_un_campo_de_profesional(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();

        $this->expectException(CapturaNoPermitida::class);

        /*
         * No es que no sepa: es que quien responde de un antecedente médico
         * ante la organización es un profesional.
         */
        $this->captura()->capturar(
            $persona,
            $this->campo('antecedentes'),
            'Me lo invento',
            $persona
        );
    }

    // ── Filtrado por sensibilidad ─────────────────────────────────────────

    public function test_la_vista_esconde_las_secciones_que_el_rol_no_alcanza(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $sujeto = $escenario->personaConsentida();
        $reclutador = $escenario->persona();

        // Tope 2: alcanza lo laboral, no lo psicológico.
        $escenario->asignarRol(
            $reclutador,
            $escenario->rol('Reclutador', ['expediente.ver'], nivelMaximo: 2)
        );

        $secciones = app(VistaExpediente::class)->paraActor($sujeto, $reclutador);
        $claves = array_column($secciones, 'clave');

        $this->assertContains('datos_generales', $claves);

        /*
         * La sección médica es nivel 3. No es que se dibuje en gris: NO SALE
         * del servidor. Una sección clínica que viaja al navegador ya se fugó
         * aunque nadie la pinte.
         */
        $this->assertNotContains('medico_relevante', $claves);
        $this->assertNotContains('notas_profesionales', $claves);
    }

    public function test_el_psicologo_si_alcanza_la_seccion_medica(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $sujeto = $escenario->personaConsentida();
        $psicologa = $escenario->persona();
        $escenario->asignarRol(
            $psicologa,
            $escenario->rol('Psicóloga', ['expediente.ver'], nivelMaximo: 4)
        );

        $claves = array_column(
            app(VistaExpediente::class)->paraActor($sujeto, $psicologa),
            'clave'
        );

        $this->assertContains('medico_relevante', $claves);
    }

    // ── Notas profesionales ───────────────────────────────────────────────

    public function test_la_nota_profesional_va_cifrada_en_la_base(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $autora = $escenario->persona();
        $expediente = $this->captura()->expedienteDe($persona);

        $nota = NotaProfesional::query()->create([
            'expediente_id' => $expediente->id,
            'organizacion_id' => $escenario->organizacion->id,
            'autor_persona_id' => $autora->id,
            'contenido' => 'Refiere insomnio desde hace tres semanas.',
            'nivel_sensibilidad_id' => 4,
            'visible_para' => 'autor',
        ]);

        $crudo = (string) DB::table('notas_profesionales')->where('id', $nota->id)->value('contenido');

        /*
         * Lo que hay en la columna no se puede leer. Un volcado de la base o
         * un respaldo robado no entrega notas clínicas (Doc 06 §4).
         */
        $this->assertStringNotContainsString('insomnio', $crudo);
        $this->assertSame('Refiere insomnio desde hace tres semanas.', $nota->refresh()->contenido);
    }

    public function test_el_titular_nunca_ve_una_nota_profesional(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $autora = $escenario->persona();
        $otraPsicologa = $escenario->persona();
        $expediente = $this->captura()->expedienteDe($persona);

        $nota = NotaProfesional::query()->create([
            'expediente_id' => $expediente->id,
            'organizacion_id' => $escenario->organizacion->id,
            'autor_persona_id' => $autora->id,
            'contenido' => 'Observaciones clínicas.',
            'nivel_sensibilidad_id' => 4,
            'visible_para' => 'nivel_4',
        ]);

        $nota->setRelation('expediente', $expediente);

        // La autora, siempre.
        $this->assertTrue($nota->laPuedeVer($autora, 4));

        // Otra profesional de nivel 4, si la nota es `nivel_4`.
        $this->assertTrue($nota->laPuedeVer($otraPsicologa, 4));

        /*
         * El titular NUNCA, ni siendo dueño del dato. No es opacidad: una nota
         * clínica en crudo, sin la conversación que la acompaña, hace daño. Lo
         * que recibe es la interpretación redactada para su audiencia.
         */
        $this->assertFalse(
            $nota->laPuedeVer($persona, 4),
            'Es la única parte del expediente que no le llega al titular en crudo.'
        );
    }

    public function test_una_nota_de_autor_no_la_ve_otro_profesional(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $persona = $escenario->persona();
        $autora = $escenario->persona();
        $otra = $escenario->persona();
        $expediente = $this->captura()->expedienteDe($persona);

        $nota = NotaProfesional::query()->create([
            'expediente_id' => $expediente->id,
            'organizacion_id' => $escenario->organizacion->id,
            'autor_persona_id' => $autora->id,
            'contenido' => 'Nota reservada.',
            'nivel_sensibilidad_id' => 4,
            'visible_para' => 'autor',
        ]);

        $nota->setRelation('expediente', $expediente);

        $this->assertFalse(
            $nota->laPuedeVer($otra, 4),
            'Alcanzar el nivel 4 no basta: `autor` significa sólo quien la escribió.'
        );
    }
}
