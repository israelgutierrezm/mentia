<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluaciones;

use App\Domain\Evaluaciones\Excepciones\AsignacionInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Servicios\CreadorAsignaciones;
use App\Domain\Evaluaciones\Servicios\GestorAsignaciones;
use App\Domain\Evaluaciones\Servicios\GestorTokens;
use App\Domain\Organizaciones\Servicios\GestorAgrupaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Apoyo\EscenarioAsignacion;
use Tests\Apoyo\EscenarioTenant;
use Tests\TestCase;

/**
 * Los cuatro casos que el Doc 08 exige para la Fase 5, y lo que los sostiene.
 */
class AsignacionesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function tokens(): GestorTokens
    {
        return app(GestorTokens::class);
    }

    // ── Caso 1: dinámica vs snapshot ──────────────────────────────────────

    public function test_una_asignacion_snapshot_no_alcanza_a_quien_llega_despues(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $grupo = $tenant->agrupacion(nombre: '3° A');
        $autor = $tenant->persona();

        $original = $tenant->persona();
        $tenant->inscribir($original, $grupo);

        $asignacion = $escenario->grupal($autor, $grupo, incluirNuevos: false);

        $this->assertSame(1, $asignacion->destinatarios()->count());

        // Llega alguien nuevo al grupo DESPUÉS de lanzar la asignación.
        $tardio = $tenant->persona();
        app(GestorAgrupaciones::class)->inscribir($grupo, $tardio);

        /*
         * El padrón se congeló a propósito. Es lo que se quiere en una campaña
         * con fecha de corte: si entrara gente después, los resultados dejarían
         * de ser comparables con el universo declarado.
         */
        $this->assertSame(1, $asignacion->refresh()->destinatarios()->count());
    }

    public function test_una_asignacion_dinamica_si_alcanza_a_quien_llega_despues(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $grupo = $tenant->agrupacion(nombre: '3° A');
        $autor = $tenant->persona();

        $original = $tenant->persona();
        $tenant->inscribir($original, $grupo);

        $asignacion = $escenario->grupal($autor, $grupo, incluirNuevos: true);

        $tardio = $tenant->persona();
        app(GestorAgrupaciones::class)->inscribir($grupo, $tardio);

        /*
         * El alumno que llega en octubre no se queda fuera del tamizaje anual.
         * Lo hace el listener de altas, no un barrido nocturno: si esperara a
         * la noche, alguien podría irse del grupo antes de recibirlo.
         */
        $this->assertSame(2, $asignacion->refresh()->destinatarios()->count());

        $this->assertTrue(
            AsignacionDestinatario::query()
                ->where('asignacion_id', $asignacion->id)
                ->where('persona_id', $tardio->id)
                ->exists()
        );
    }

    public function test_la_expansion_no_duplica_a_quien_ya_estaba(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $grupo = $tenant->agrupacion();
        $persona = $tenant->persona();
        $tenant->inscribir($persona, $grupo);

        $asignacion = $escenario->grupal($tenant->persona(), $grupo, incluirNuevos: true);

        // Reconciliación manual: correrla dos veces no puede duplicar.
        app(CreadorAsignaciones::class)->expandirDinamica($asignacion);
        app(CreadorAsignaciones::class)->expandirDinamica($asignacion);

        $this->assertSame(1, $asignacion->refresh()->destinatarios()->count());
    }

    public function test_una_membresia_dada_de_baja_no_recibe_la_asignacion(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $grupo = $tenant->agrupacion();
        $vigente = $tenant->persona();
        $tenant->inscribir($vigente, $grupo);

        // Se fue del grupo el mes pasado.
        $exalumno = $tenant->persona();
        $tenant->inscribir($exalumno, $grupo, Carbon::now()->subMonth()->toDateString());

        $asignacion = $escenario->grupal($tenant->persona(), $grupo);

        $this->assertSame(1, $asignacion->destinatarios()->count());
        $this->assertFalse(
            AsignacionDestinatario::query()
                ->where('asignacion_id', $asignacion->id)
                ->where('persona_id', $exalumno->id)
                ->exists(),
            'Quien se dio de baja en julio no debe recibir el tamizaje de septiembre.'
        );
    }

    // ── Caso 2: token expirado ────────────────────────────────────────────

    public function test_un_token_expirado_no_abre_nada(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual(
            $tenant->persona(),
            [$persona],
            ventanaFin: Carbon::now()->addDay()->toDateTimeString()
        );

        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);
        $token = $this->tokens()->generar($destinatario);

        $this->assertNotNull($this->tokens()->resolver($token));

        // Dos días después: la ventana ya cerró.
        $despues = Carbon::now()->addDays(2);

        $this->assertNull(
            $this->tokens()->resolver($token, $despues),
            'El token expira con la ventana de su asignación, no con un plazo propio.'
        );
    }

    public function test_cerrar_la_asignacion_invalida_sus_tokens_al_instante(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);
        $token = $this->tokens()->generar($destinatario);

        $this->assertNotNull($this->tokens()->resolver($token));

        app(GestorAsignaciones::class)->cerrar($asignacion);

        /*
         * Sin esto, una liga enviada hace tres días seguiría abriendo la
         * evaluación después de cerrada.
         */
        $this->assertNull($this->tokens()->resolver($token));
    }

    // ── Caso 3: doble uso de token ────────────────────────────────────────

    /**
     * Reanudación: la liga del correo vuelve a entrar a lo que quedó a medias.
     *
     * La regla estricta —token muerto al primer canje— protege del reenvío por
     * WhatsApp y castiga el caso mucho más común, que es cerrar la pestaña. Con
     * ella, cada cierre accidental abandona un instrumento a la mitad.
     */
    public function test_el_token_vuelve_a_entrar_mientras_la_aplicacion_sigue_en_curso(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);
        $token = $this->tokens()->generar($destinatario);

        $primero = $this->tokens()->canjear($token);

        $this->assertNotNull($primero);
        $this->assertSame('en_curso', $primero->estado);

        $segundo = $this->tokens()->canjear($token);

        $this->assertNotNull($segundo, 'Cerrar la pestaña no puede costar el instrumento entero.');
        $this->assertSame($primero->id, $segundo->id);

        // La fecha del PRIMER canje se conserva: es la que sirve en la bitácora.
        $this->assertTrue($primero->token_usado_en->equalTo($segundo->token_usado_en));
    }

    public function test_el_token_muere_cuando_el_destinatario_ya_contesto(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);
        $token = $this->tokens()->generar($destinatario);

        $this->assertNotNull($this->tokens()->canjear($token));

        $destinatario->refresh()->update(['estado' => 'completada']);

        /*
         * Aquí sí: una liga reenviada por WhatsApp llega a más gente de la que
         * se pensó, y lo que el token no puede hacer NUNCA es abrir un intento
         * nuevo sobre una evaluación ya contestada.
         */
        $this->assertNull(
            $this->tokens()->canjear($token),
            'Contestada la evaluación, el token está muerto.'
        );
    }

    public function test_el_token_se_guarda_hasheado(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);

        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);
        $token = $this->tokens()->generar($destinatario);

        $guardado = (string) DB::table('asignacion_destinatarios')
            ->where('id', $destinatario->id)
            ->value('token');

        /*
         * Quien lea la base —un respaldo, un volcado, una consulta de
         * soporte— no debe poder entrar a contestar en nombre de nadie.
         */
        $this->assertNotSame($token, $guardado);
        $this->assertSame(hash('sha256', $token), $guardado);
    }

    public function test_un_token_inventado_no_resuelve(): void
    {
        $this->assertNull($this->tokens()->resolver(str_repeat('a', 64)));
        $this->assertNull($this->tokens()->resolver('corto'));
    }

    // ── Caso 4: discreta invisible para rol no autorizado ─────────────────

    public function test_una_asignacion_discreta_no_la_ve_un_rol_de_nivel_bajo(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $psicologa = $tenant->persona();
        $coordinador = $tenant->persona();

        $discreta = $escenario->individual($psicologa, [$tenant->persona()], esDiscreta: true);
        $normal = $escenario->individual($psicologa, [$tenant->persona()]);

        // El coordinador es nivel 2: ve la normal, no la discreta.
        $visibles = Asignacion::query()
            ->visiblesPara($coordinador, nivelDelActor: 2)
            ->pluck('folio')
            ->all();

        $this->assertContains($normal->folio, $visibles);
        $this->assertNotContains(
            $discreta->folio,
            $visibles,
            'Que el área sepa que existe esa evaluación ya es una filtración, '
            .'aunque nadie vea el resultado.'
        );
    }

    public function test_quien_la_creo_si_ve_su_propia_discreta(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $psicologa = $tenant->persona();
        $discreta = $escenario->individual($psicologa, [$tenant->persona()], esDiscreta: true);

        $visibles = Asignacion::query()
            ->visiblesPara($psicologa, nivelDelActor: 2)
            ->pluck('folio')
            ->all();

        $this->assertContains($discreta->folio, $visibles);
    }

    public function test_un_rol_de_nivel_cuatro_ve_las_discretas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $discreta = $escenario->individual($tenant->persona(), [$tenant->persona()], esDiscreta: true);
        $otraPsicologa = $tenant->persona();

        $visibles = Asignacion::query()
            ->visiblesPara($otraPsicologa, nivelDelActor: 4)
            ->pluck('folio')
            ->all();

        $this->assertContains(
            $discreta->folio,
            $visibles,
            'Quien tiene que poder atenderlas las ve (Doc 06 §1).'
        );
    }

    // ── Anonimato ─────────────────────────────────────────────────────────

    public function test_el_avance_de_una_anonima_solo_da_conteos(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual(
            $tenant->persona(),
            [$tenant->persona(), $tenant->persona()],
            esAnonima: true
        );

        $avance = app(GestorAsignaciones::class)->avance($asignacion);

        $this->assertTrue($avance['es_anonima']);
        $this->assertSame(2, $avance['total']);

        $this->expectException(AsignacionInvalida::class);

        /*
         * Saber quién ya contestó y quién no permite deducir de quién es cada
         * respuesta en un centro de trabajo chico, y eso destruye el anonimato
         * que hace que la gente conteste con la verdad.
         */
        app(GestorAsignaciones::class)->destinatariosDetallados($asignacion);
    }

    public function test_el_detalle_de_una_no_anonima_si_lista_personas(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $persona = $tenant->persona();
        $asignacion = $escenario->individual($tenant->persona(), [$persona]);

        $detalle = app(GestorAsignaciones::class)->destinatariosDetallados($asignacion);

        $this->assertCount(1, $detalle);
        $this->assertSame($persona->uuid, $detalle[0]['persona_uuid']);
    }

    // ── Instrumento XOR batería ───────────────────────────────────────────

    public function test_no_se_crea_una_asignacion_con_instrumento_y_bateria(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $bateria = \App\Domain\Evaluaciones\Modelos\Bateria::query()->create([
            'clave' => 'b1',
            'nombre' => 'Batería',
            'estado' => 'activa',
        ]);

        $this->expectException(AsignacionInvalida::class);

        app(CreadorAsignaciones::class)->crear(
            proposito: $escenario->proposito,
            autor: $tenant->persona(),
            origenTipo: 'individual',
            destinatariosUuid: [$tenant->persona()->uuid],
            versionInstrumentoId: $escenario->instrumento->version->id,
            bateriaId: $bateria->id,
        );
    }

    public function test_la_base_tambien_rechaza_instrumento_y_bateria_a_la_vez(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $bateria = \App\Domain\Evaluaciones\Modelos\Bateria::query()->create([
            'clave' => 'b2',
            'nombre' => 'Batería',
            'estado' => 'activa',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        /*
         * El CHECK está en MySQL, no sólo en el servicio: un INSERT que se
         * salte el dominio tampoco puede dejar una asignación que el motor de
         * aplicación no sabría cómo presentar.
         */
        Asignacion::query()->create([
            'folio' => 'A-2026-CHECK1',
            'organizacion_id' => $tenant->organizacion->id,
            'version_instrumento_id' => $escenario->instrumento->version->id,
            'bateria_id' => $bateria->id,
            'proposito_id' => $escenario->proposito->id,
            'origen_tipo' => 'individual',
            'asignado_por' => $tenant->persona()->id,
            'ventana_inicio' => Carbon::now(),
            'ventana_fin' => Carbon::now()->addDay(),
            'estado' => 'activa',
        ]);
    }

    // ── Exenciones ────────────────────────────────────────────────────────

    public function test_exentar_exige_motivo_y_retira_la_liga(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->setRelation('asignacion', $asignacion);

        $token = $this->tokens()->generar($destinatario);

        app(GestorAsignaciones::class)->exentar(
            $destinatario,
            'Incapacidad médica durante toda la ventana.'
        );

        $this->assertSame('exenta', $destinatario->refresh()->estado);
        $this->assertNotNull($destinatario->motivo_exencion);

        $this->assertNull(
            $this->tokens()->resolver($token),
            'Exentar y dejar el token vivo permitiría contestar igual.'
        );
    }

    public function test_no_se_exenta_a_quien_ya_contesto(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual($tenant->persona(), [$tenant->persona()]);
        $destinatario = $asignacion->destinatarios()->first();
        $destinatario->update(['estado' => 'completada']);

        $this->expectException(AsignacionInvalida::class);

        app(GestorAsignaciones::class)->exentar($destinatario, 'Ya no aplica.');
    }

    public function test_cerrar_marca_expirados_a_los_pendientes(): void
    {
        $tenant = EscenarioTenant::nuevo()->activar();
        $escenario = new EscenarioAsignacion($tenant);

        $asignacion = $escenario->individual(
            $tenant->persona(),
            [$tenant->persona(), $tenant->persona()]
        );

        app(GestorAsignaciones::class)->cerrar($asignacion);

        $this->assertSame(
            2,
            AsignacionDestinatario::query()
                ->where('asignacion_id', $asignacion->id)
                ->where('estado', 'expirada')
                ->count()
        );
    }
}
