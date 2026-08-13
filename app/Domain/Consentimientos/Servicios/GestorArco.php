<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Servicios;

use App\Domain\Accesos\Servicios\RegistroBitacora;
use App\Domain\Consentimientos\Modelos\SolicitudArco;
use App\Domain\Expedientes\Servicios\VistaExpediente;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Derechos ARCO (Doc 06 §3 — LFPDPPP).
 *
 * Lo que este servicio garantiza no es que haya un formulario: es que haya
 * PLAZOS registrados y respuesta documentada. La ley da 20 días hábiles para
 * decir si procede y 15 más para hacerla efectiva, y sin fecha de recepción no
 * hay forma de demostrar que se contestó a tiempo.
 */
class GestorArco
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly RegistroBitacora $bitacora,
        private readonly VistaExpediente $expediente,
    ) {}

    public function recibir(
        Persona $titular,
        Persona $presentaLa,
        string $derecho,
        string $descripcion,
    ): SolicitudArco {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        $ahora = Carbon::now();

        $solicitud = SolicitudArco::query()->create([
            'organizacion_id' => $organizacionId,
            'persona_id' => $titular->id,
            'presentada_por' => $presentaLa->id,
            'derecho' => $derecho,
            'descripcion' => $descripcion,
            'estado' => 'recibida',
            'recibida_en' => $ahora,

            /*
             * El plazo se calcula AL RECIBIR y se guarda. Recalcularlo después
             * con el calendario de hoy daría una fecha distinta si cambian los
             * asuetos, y el plazo que corre es el que corría el día que entró.
             */
            'vence_respuesta' => $this->sumarDiasHabiles($ahora, SolicitudArco::DIAS_RESPUESTA),
        ]);

        $this->bitacora->registrarAccion(
            organizacionId: $organizacionId,
            accion: 'arco.recibida',
            recursoTipo: 'SolicitudArco',
            recursoId: $solicitud->id,
            personaAfectadaId: $titular->id,
            motivo: 'Derecho de '.$derecho,
        );

        return $solicitud;
    }

    /**
     * Responde la solicitud. La respuesta es OBLIGATORIA, incluso si es una
     * negativa: la ley exige respuesta documentada, no silencio.
     */
    public function responder(
        SolicitudArco $solicitud,
        Persona $quienResponde,
        string $estado,
        string $respuesta,
        ?string $excepciones = null,
    ): SolicitudArco {
        if (trim($respuesta) === '') {
            throw new RuntimeException('La respuesta a una solicitud ARCO no puede ir vacía.');
        }

        if (! in_array($estado, ['procedente', 'improcedente'], true)) {
            throw new RuntimeException('Una solicitud se responde como procedente o improcedente.');
        }

        /*
         * Declararla improcedente SIN decir por qué es lo que convierte una
         * negativa legítima en una queja ante el INAI. Hay datos que la
         * organización está obligada a conservar —la bitácora, por ejemplo— y
         * eso se puede sostener; lo que no se sostiene es no explicarlo.
         */
        if ($estado === 'improcedente' && ($excepciones === null || trim($excepciones) === '')) {
            throw new RuntimeException(
                'Una solicitud improcedente tiene que documentar en qué excepción se funda.'
            );
        }

        return DB::transaction(function () use (
            $solicitud, $quienResponde, $estado, $respuesta, $excepciones
        ): SolicitudArco {
            $ahora = Carbon::now();

            $solicitud->update([
                'estado' => $estado,
                'respondida_en' => $ahora,
                'respondida_por' => $quienResponde->id,
                'respuesta' => $respuesta,
                'excepciones_aplicadas' => $excepciones,
                'vence_cumplimiento' => $estado === 'procedente'
                    ? $this->sumarDiasHabiles($ahora, SolicitudArco::DIAS_CUMPLIMIENTO)
                    : null,
            ]);

            $this->bitacora->registrarAccion(
                organizacionId: $solicitud->organizacion_id,
                accion: 'arco.respondida',
                recursoTipo: 'SolicitudArco',
                recursoId: $solicitud->id,
                personaAfectadaId: $solicitud->persona_id,
                motivo: $estado,
            );

            return $solicitud->refresh();
        });
    }

    /**
     * El expediente exportable del derecho de ACCESO.
     *
     * Sale por `VistaExpediente`, el mismo servicio que filtra sección por
     * sección: el titular tiene derecho a SUS datos, no a las notas
     * profesionales que otro escribió sobre él ni a lo que la organización
     * anotó para su uso interno. Es su expediente, no el archivo completo.
     *
     * @return array<string, mixed>
     */
    public function exportarExpediente(SolicitudArco $solicitud): array
    {
        $titular = $solicitud->persona;

        $this->bitacora->registrarAccion(
            organizacionId: $solicitud->organizacion_id,
            accion: 'arco.exportacion',
            recursoTipo: 'SolicitudArco',
            recursoId: $solicitud->id,
            personaAfectadaId: $titular->id,
            motivo: 'Derecho de acceso',
        );

        return [
            'generado_en' => Carbon::now()->toIso8601String(),
            'folio' => $solicitud->uuid,

            'persona' => [
                'nombre' => $titular->nombreCompleto(),
                'curp' => $titular->curp,
                'fecha_nacimiento' => $titular->fecha_nacimiento?->toDateString(),
            ],

            // El titular como ACTOR de su propio expediente: sale lo que le
            // corresponde ver, no el archivo completo de la organización.
            'expediente' => $this->expediente->paraActor($titular, $titular),

            /*
             * La serie longitudinal va COMPLETA, no filtrada por organización:
             * es el expediente de la persona y le pertenece entero, aunque
             * algunos puntos se midieran en otro tenant.
             */
            'resultados' => ResultadoNormalizado::query()
                ->where('persona_id', $titular->id)
                ->orderBy('fecha')
                ->get()
                ->map(static fn (ResultadoNormalizado $punto): array => [
                    'fecha' => $punto->fecha->toDateString(),
                    'constructo' => $punto->constructo,
                    'tipo_norma' => $punto->tipo_norma,
                    'valor' => $punto->valor,
                    'bandera' => $punto->bandera,
                ])->values()->all(),
        ];
    }

    /**
     * Suma días HÁBILES, saltando sábados y domingos.
     *
     * No contempla los días de descanso obligatorio del artículo 74 de la LFT
     * ni los asuetos que cada organización declare: sumarlos exige un
     * calendario configurable que todavía no existe. El plazo que sale de aquí
     * es por tanto MÁS CORTO o igual que el real, y quedarse corto en un plazo
     * legal es el error que no hace daño.
     */
    public function sumarDiasHabiles(Carbon $desde, int $dias): Carbon
    {
        $fecha = $desde->copy()->startOfDay();

        while ($dias > 0) {
            $fecha->addDay();

            if (! $fecha->isWeekend()) {
                $dias--;
            }
        }

        return $fecha;
    }
}
