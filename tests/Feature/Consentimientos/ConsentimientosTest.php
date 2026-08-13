<?php

declare(strict_types=1);

namespace Tests\Feature\Consentimientos;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Consentimientos\Excepciones\ConsentimientoInvalido;
use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Consentimientos\Modelos\TextoConsentimiento;
use App\Domain\Consentimientos\Modelos\TipoConsentimiento;
use App\Domain\Consentimientos\Servicios\GestorConsentimientos;
use App\Domain\Consentimientos\Servicios\TransicionMayoriaEdad;
use App\Domain\Expedientes\Modelos\Expediente;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Los tres casos que el Doc 08 exige para la Fase 2, y el aparato que los
 * sostiene.
 */
class ConsentimientosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function gestor(): GestorConsentimientos
    {
        return app(GestorConsentimientos::class);
    }

    private function accesos(): AccesoService
    {
        return app(AccesoService::class);
    }

    private function texto(string $tipo = TipoConsentimiento::TRATAMIENTO): TextoConsentimiento
    {
        return TextoConsentimiento::query()
            ->whereHas('tipo', fn ($consulta) => $consulta->where('clave', $tipo))
            ->whereNull('organizacion_id')
            ->firstOrFail();
    }

    // ── Caso 1 del Doc 08: revocación con efecto inmediato ────────────────

    public function test_la_revocacion_corta_el_acceso_de_inmediato(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $actor = $escenario->persona();
        $sujeto = $escenario->persona();
        $escenario->asignarRol($actor, $escenario->rol('Orientador', ['expediente.ver'], 2));

        $consentimiento = $this->gestor()->otorgar($sujeto, $this->texto(), $sujeto);

        $this->assertTrue(
            $this->accesos()->autorizar($actor, 'expediente.ver', $sujeto)->permitido
        );

        $this->gestor()->revocar($consentimiento, 'La persona cambió de opinión.');

        /*
         * Inmediato: no al día siguiente ni cuando pase el job nocturno.
         * `Consentimiento::estaVigente()` mira `revocado_en`, así que la
         * siguiente decisión ya lo ve cerrado. Es lo que la LFPDPPP entiende
         * por revocación.
         */
        $decision = $this->accesos()->autorizar($actor, 'expediente.ver', $sujeto);

        $this->assertTrue($decision->denegado());
        $this->assertSame('revocado', $consentimiento->refresh()->estado);
    }

    // ── Caso 2 del Doc 08: tutor no validado sin acceso ───────────────────

    public function test_un_tutor_sin_validar_no_puede_ni_ver_ni_consentir(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $supuestaMadre = $escenario->persona();
        $menor = $escenario->persona();

        // Declarada, NO acreditada.
        $escenario->tutoria($supuestaMadre, $menor, estado: 'pendiente_validacion');

        $this->assertTrue(
            $this->accesos()->autorizar($supuestaMadre, 'expediente.ver', $menor)->denegado(),
            'El parentesco declarado no acredita nada.'
        );

        // Y tampoco puede firmar por el menor: si pudiera, se abriría el
        // acceso a sí misma con su propia firma.
        $this->expectException(ConsentimientoInvalido::class);

        $this->gestor()->otorgar($menor, $this->texto(), $supuestaMadre);
    }

    public function test_un_tutor_acreditado_si_consiente_por_el_menor(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $menor = $escenario->persona();
        $escenario->tutoria($madre, $menor);

        $consentimiento = $this->gestor()->otorgar($menor, $this->texto(), $madre);

        $this->assertSame('tutor', $consentimiento->relacion);
        $this->assertSame($menor->id, $consentimiento->persona_id);
        $this->assertSame($madre->id, $consentimiento->otorgado_por_persona_id);
    }

    // ── Caso 3 del Doc 08: menor → mayoría de edad ────────────────────────

    public function test_al_cumplir_dieciocho_se_extingue_la_tutela_y_se_bloquea_a_terceros(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();

        // Cumplió 18 hace un mes.
        $persona = Persona::factory()->create([
            'fecha_nacimiento' => Carbon::now()->subYears(18)->subMonth()->toDateString(),
        ]);
        app(\App\Domain\Personas\Servicios\RegistroPersonas::class)
            ->vincular($persona, $escenario->organizacion);

        $escenario->tutoria($madre, $persona);
        $this->gestor()->otorgar($persona, $this->texto(), $madre);

        $orientador = $escenario->persona();
        $escenario->asignarRol($orientador, $escenario->rol('Orientador', ['expediente.ver'], 2));

        $this->assertTrue(
            $this->accesos()->autorizar($orientador, 'expediente.ver', $persona)->permitido,
            'Antes de la transición, el consentimiento del tutor ampara.'
        );

        app(TransicionMayoriaEdad::class)->correr();

        // 1. La tutela se extingue.
        $this->assertSame(
            'extinta_mayoria_edad',
            Tutoria::query()->where('menor_persona_id', $persona->id)->value('estado')
        );

        // 2. Lo que firmó el tutor queda en el aire.
        $this->assertSame(
            'pendiente_reconsentimiento',
            Consentimiento::query()->where('persona_id', $persona->id)->value('estado')
        );

        // 3. Y terceros quedan fuera hasta que la persona decida.
        $this->assertTrue(
            $this->accesos()->autorizar($orientador, 'expediente.ver', $persona)->denegado(),
            'Lo que la madre autorizó cuando tenía doce años no vincula a quien ya es mayor.'
        );

        // La madre tampoco: dejó de ser tutora.
        $this->assertTrue(
            $this->accesos()->autorizar($madre, 'expediente.ver', $persona)->denegado()
        );

        // Pero la persona sí entra a lo suyo: el bloqueo la protege a ella.
        $this->assertTrue(
            $this->accesos()->autorizar($persona, 'expediente.ver', $persona)->permitido
        );
    }

    public function test_al_re_consentir_se_levanta_el_bloqueo(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();

        $madre = $escenario->persona();
        $persona = Persona::factory()->create([
            'fecha_nacimiento' => Carbon::now()->subYears(18)->subMonth()->toDateString(),
        ]);
        app(\App\Domain\Personas\Servicios\RegistroPersonas::class)
            ->vincular($persona, $escenario->organizacion);

        $escenario->tutoria($madre, $persona);
        $this->gestor()->otorgar($persona, $this->texto(), $madre);
        app(TransicionMayoriaEdad::class)->correr();

        $this->assertSame(
            'bloqueado',
            Expediente::query()->where('persona_id', $persona->id)->value('estado')
        );

        // La persona firma por sí misma.
        $this->gestor()->otorgar($persona, $this->texto(), $persona);

        $this->assertSame(
            'activo',
            Expediente::query()->where('persona_id', $persona->id)->value('estado'),
            'El desbloqueo es consecuencia de consentir, no un botón que alguien deba apretar.'
        );

        $orientador = $escenario->persona();
        $escenario->asignarRol($orientador, $escenario->rol('Orientador', ['expediente.ver'], 2));

        $this->assertTrue(
            $this->accesos()->autorizar($orientador, 'expediente.ver', $persona)->permitido
        );
    }

    // ── Inmutabilidad del texto ───────────────────────────────────────────

    public function test_un_texto_publicado_no_se_edita(): void
    {
        $texto = $this->texto();

        $this->expectException(LogicException::class);

        // Si se pudiera, un tenant cambiaría el texto después de que mil
        // personas lo firmaron y todos esos consentimientos ampararían algo
        // que nadie aceptó.
        $texto->update(['cuerpo' => 'Ahora dice otra cosa.']);
    }

    public function test_el_hash_detecta_un_texto_alterado_por_fuera(): void
    {
        $texto = $this->texto();

        $this->assertTrue($texto->integroSegunHash());

        // Un UPDATE a mano en la base, saltándose el modelo.
        \Illuminate\Support\Facades\DB::table('textos_consentimiento')
            ->where('id', $texto->id)
            ->update(['cuerpo' => 'Texto sustituido sin pasar por la aplicación.']);

        $this->assertFalse($texto->refresh()->integroSegunHash());
    }

    public function test_no_se_firma_un_texto_alterado(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();
        $texto = $this->texto();

        \Illuminate\Support\Facades\DB::table('textos_consentimiento')
            ->where('id', $texto->id)
            ->update(['cuerpo' => 'Otra cosa.']);

        $this->expectException(ConsentimientoInvalido::class);

        $this->gestor()->otorgar($persona, $texto->refresh(), $persona);
    }

    // ── Finalidad y compartición ──────────────────────────────────────────

    public function test_otorgar_de_nuevo_reemplaza_el_anterior(): void
    {
        $escenario = EscenarioTenant::nuevo()->activar();
        $persona = $escenario->persona();

        $primero = $this->gestor()->otorgar($persona, $this->texto(), $persona);
        $segundo = $this->gestor()->otorgar($persona, $this->texto(), $persona);

        $this->assertSame('revocado', $primero->refresh()->estado);
        $this->assertSame('vigente', $segundo->refresh()->estado);

        $this->assertSame(
            1,
            Consentimiento::query()->where('persona_id', $persona->id)
                ->where('estado', 'vigente')->count(),
            'Dos consentimientos vigentes del mismo tipo harían imposible saber cuál rige.'
        );
    }

    public function test_la_comparticion_abre_el_expediente_a_otra_organizacion(): void
    {
        $a = EscenarioTenant::nuevo();
        $b = EscenarioTenant::nuevo();

        $a->activar();
        $persona = $a->persona();
        $consentimiento = $this->gestor()->otorgar(
            $persona,
            $this->texto(TipoConsentimiento::COMPARTICION),
            $persona
        );

        // En B, la persona no está vinculada: sin compartición, nada.
        $b->activar();
        $reclutador = $b->persona();
        $b->asignarRol($reclutador, $b->rol('Reclutador', ['expediente.ver'], 2));

        $this->assertTrue(
            $this->accesos()->autorizar($reclutador, 'expediente.ver', $persona)->denegado(),
            'Estar en otro tenant no da acceso a lo que la persona generó fuera.'
        );

        $a->activar();
        $comparticion = $this->gestor()->compartir(
            $consentimiento,
            $b->organizacion->id,
            alcance: 'resumen'
        );

        $b->activar();
        $this->assertTrue(
            $this->accesos()->autorizar($reclutador, 'expediente.ver', $persona)->permitido,
            'Con compartición vigente, sí.'
        );

        // Y revocar el consentimiento cierra la compartición que colgaba de él.
        $a->activar();
        $this->gestor()->revocar($consentimiento);

        $b->activar();
        $this->assertTrue(
            $this->accesos()->autorizar($reclutador, 'expediente.ver', $persona)->denegado(),
            'Revocar el consentimiento tiene que cerrar lo que dependía de él.'
        );

        $this->assertNotNull($comparticion->refresh()->revocado_en);
    }
}
