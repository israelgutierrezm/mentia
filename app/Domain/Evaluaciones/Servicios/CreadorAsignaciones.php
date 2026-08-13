<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Alertas\Servicios\ProtocoloDeActuacion;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\AsignacionInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Modelos\BateriaInstrumento;
use App\Domain\Evaluaciones\Modelos\Proposito;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Crea asignaciones y expande sus destinatarios.
 *
 * El punto delicado es la expansión de agrupación: quién recibe la evaluación
 * y quién no. Se respetan las membresías VIGENTES —quien se dio de baja del
 * grupo en julio no recibe el tamizaje de septiembre— y el flag
 * `incluir_nuevos_miembros` decide si el padrón queda congelado o sigue
 * creciendo.
 */
class CreadorAsignaciones
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly GestorTokens $tokens,
        private readonly ProtocoloDeActuacion $protocolo,
    ) {}

    /**
     * @param  list<string>  $destinatariosUuid  Sólo para origen individual.
     *
     * @throws AsignacionInvalida
     */
    public function crear(
        Proposito $proposito,
        Persona $autor,
        string $origenTipo,
        ?Agrupacion $agrupacion = null,
        array $destinatariosUuid = [],
        ?string $ventanaInicio = null,
        ?string $ventanaFin = null,
        ?int $versionInstrumentoId = null,
        ?int $bateriaId = null,
        bool $incluirNuevosMiembros = false,
        bool $esDiscreta = false,
        bool $esAnonima = false,
        int $intentosPermitidos = 1,
        ?string $modoPresentacion = null,
    ): Asignacion {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        /*
         * El propósito sólo rellena cuando NO se pidió ninguno de los dos.
         *
         * Rellenar cada uno por separado producía el error que cazó una
         * prueba: pedir explícitamente una batería contra un propósito que
         * trae instrumento dejaba los DOS puestos y chocaba contra el XOR.
         * Quien especifica uno está diciendo justamente que no quiere el
         * default del otro.
         */
        if ($versionInstrumentoId === null && $bateriaId === null) {
            $versionInstrumentoId = $proposito->version_instrumento_id;
            $bateriaId = $proposito->bateria_id;
        }

        $this->exigirExactamenteUno($versionInstrumentoId, $bateriaId);
        $this->exigirProtocoloDeActuacion($versionInstrumentoId, $bateriaId, $organizacionId);

        $inicio = $ventanaInicio !== null ? Carbon::parse($ventanaInicio) : Carbon::now();
        $fin = $ventanaFin !== null
            ? Carbon::parse($ventanaFin)
            : $inicio->copy()->addDays($proposito->vigencia_dias_default);

        if ($fin->lessThanOrEqualTo($inicio)) {
            throw AsignacionInvalida::porVentanaInvertida();
        }

        return DB::transaction(function () use (
            $proposito, $autor, $origenTipo, $agrupacion, $destinatariosUuid,
            $inicio, $fin, $versionInstrumentoId, $bateriaId, $incluirNuevosMiembros,
            $esDiscreta, $esAnonima, $intentosPermitidos, $modoPresentacion, $organizacionId
        ): Asignacion {
            $asignacion = Asignacion::query()->create([
                'folio' => $this->folio(),
                'organizacion_id' => $organizacionId,
                'version_instrumento_id' => $versionInstrumentoId,
                'bateria_id' => $bateriaId,
                'proposito_id' => $proposito->id,
                'origen_tipo' => $origenTipo,
                'agrupacion_id' => $agrupacion?->id,
                'incluir_nuevos_miembros' => $origenTipo === 'agrupacion' && $incluirNuevosMiembros,
                'asignado_por' => $autor->id,
                'es_discreta' => $esDiscreta,
                'es_anonima' => $esAnonima,
                'ventana_inicio' => $inicio,
                'ventana_fin' => $fin,
                'intentos_permitidos' => $intentosPermitidos,
                'modo_presentacion' => $modoPresentacion ?? $proposito->modo_presentacion_default,
                'requiere_consentimiento' => true,
                'tipo_consentimiento_id' => $proposito->tipo_consentimiento_id,
                'estado' => 'activa',
            ]);

            $personas = $origenTipo === 'agrupacion' && $agrupacion !== null
                ? $this->miembrosVigentesDe($agrupacion)
                : $this->personasPorUuid($destinatariosUuid);

            if ($personas->isEmpty()) {
                throw AsignacionInvalida::porNoTenerDestinatarios();
            }

            foreach ($personas as $persona) {
                $this->agregarDestinatario($asignacion, $persona);
            }

            return $asignacion;
        });
    }

    /**
     * Agrega una persona a una asignación ya creada, con su token.
     *
     * Es idempotente: el único de (asignacion, persona) impide duplicar, y
     * volver a agregar a alguien que ya estaba no le genera un token nuevo
     * —eso invalidaría la liga que ya recibió—.
     */
    public function agregarDestinatario(
        Asignacion $asignacion,
        Persona $persona,
        ?Persona $quienResponde = null,
    ): AsignacionDestinatario {
        $existente = AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->where('persona_id', $persona->id)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $destinatario = AsignacionDestinatario::query()->create([
            'asignacion_id' => $asignacion->id,
            'persona_id' => $persona->id,
            'quien_responde_persona_id' => $quienResponde?->id,
            'estado' => $asignacion->requiere_consentimiento
                ? 'consentimiento_pendiente'
                : 'pendiente',
        ]);

        $destinatario->setRelation('asignacion', $asignacion);
        $this->tokens->generar($destinatario);

        return $destinatario->refresh();
    }

    /**
     * Los miembros VIGENTES de la agrupación.
     *
     * Vigentes, no todos: quien se dio de baja del grupo en julio no debe
     * recibir el tamizaje de septiembre, y su membresía histórica sigue ahí
     * porque es parte de su línea de vida institucional.
     *
     * @return Collection<int, Persona>
     */
    public function miembrosVigentesDe(Agrupacion $agrupacion): Collection
    {
        $ids = AgrupacionMiembro::query()
            ->where('agrupacion_id', $agrupacion->id)
            ->where('rol_en_agrupacion', 'evaluado')
            ->vigentes()
            ->pluck('persona_id');

        /** @var Collection<int, Persona> */
        return Persona::query()->whereIn('id', $ids)->get();
    }

    /**
     * Expande una asignación DINÁMICA con quien haya entrado al grupo después.
     *
     * Lo llama el listener de altas de membresía, y también sirve como
     * reconciliación manual: si el listener falló una vez, correr esto
     * repara el padrón sin duplicar a nadie.
     */
    public function expandirDinamica(Asignacion $asignacion): int
    {
        if (! $asignacion->esDinamica() || ! $asignacion->ventanaAbierta()) {
            return 0;
        }

        $agrupacion = $asignacion->agrupacion;

        if ($agrupacion === null) {
            return 0;
        }

        $yaEstan = $asignacion->destinatarios()->pluck('persona_id')->all();

        $nuevos = $this->miembrosVigentesDe($agrupacion)
            ->reject(fn (Persona $persona): bool => in_array($persona->id, $yaEstan, true));

        foreach ($nuevos as $persona) {
            $this->agregarDestinatario($asignacion, $persona);
        }

        return $nuevos->count();
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, Persona>
     */
    private function personasPorUuid(array $uuids): Collection
    {
        if ($uuids === []) {
            return new Collection;
        }

        /*
         * Sólo personas VINCULADAS a la organización activa. `personas` es
         * global: sin esta puerta, mandar el uuid de cualquiera la metería
         * como destinataria de este tenant.
         *
         * @var Collection<int, Persona>
         */
        return Persona::query()
            ->whereIn('uuid', $uuids)
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->get();
    }

    /**
     * @throws AsignacionInvalida
     */
    /**
     * Sin protocolo de actuación registrado NO se asigna un instrumento con
     * reactivos centinela (Doc 06 §5).
     *
     * La comprobación va aquí, en la creación, y no al habilitar el
     * instrumento: habilitar es un acto administrativo que puede pasar meses
     * antes, y el protocolo puede haberse borrado en medio. Lo que importa es
     * que EN EL MOMENTO en que alguien va a contestar haya quién responda.
     *
     * @throws AsignacionInvalida
     */
    private function exigirProtocoloDeActuacion(
        ?int $versionId,
        ?int $bateriaId,
        int $organizacionId,
    ): void {
        $versiones = $versionId !== null
            ? VersionInstrumento::query()->whereKey($versionId)->get()
            : VersionInstrumento::query()
                ->whereIn(
                    'id',
                    BateriaInstrumento::query()
                        ->where('bateria_id', $bateriaId)
                        ->pluck('version_instrumento_id')
                )
                ->get();

        $organizacion = Organizacion::query()->findOrFail($organizacionId);

        foreach ($versiones as $version) {
            $motivo = $this->protocolo->motivoDeBloqueo($organizacion, $version);

            if ($motivo !== null) {
                throw AsignacionInvalida::porFaltarProtocolo($motivo);
            }
        }
    }

    private function exigirExactamenteUno(?int $versionId, ?int $bateriaId): void
    {
        /*
         * El CHECK de la base también lo impide, pero un choque de constraint
         * llega como error de SQL sin decir qué hacer. Aquí se explica.
         */
        if (($versionId === null) === ($bateriaId === null)) {
            throw AsignacionInvalida::porInstrumentoYBateria();
        }
    }

    /**
     * Folio legible y no adivinable.
     *
     * Lleva el año para que se lea de un vistazo de cuándo es, y seis
     * caracteres aleatorios en vez de un consecutivo: un consecutivo revela
     * cuántas evaluaciones lanza la organización y permite pedir la de al lado.
     */
    private function folio(): string
    {
        do {
            $folio = sprintf('A-%s-%s', Carbon::now()->format('Y'), Str::upper(Str::random(6)));
        } while (Asignacion::query()->withoutGlobalScopes()->where('folio', $folio)->exists());

        return $folio;
    }
}
