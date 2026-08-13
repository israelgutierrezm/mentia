<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Servicios;

use App\Domain\Consentimientos\Excepciones\ConsentimientoInvalido;
use App\Domain\Consentimientos\Modelos\ComparticionExpediente;
use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Consentimientos\Modelos\TextoConsentimiento;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Otorgar, revocar y compartir.
 */
class GestorConsentimientos
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly TransicionMayoriaEdad $transicion,
    ) {}

    /**
     * Otorga un consentimiento sobre el TITULAR de los datos.
     *
     * Quien firma puede ser el titular o un tutor VIGENTE; la relación queda
     * registrada porque de ella depende qué pasa al cumplir la mayoría de edad.
     *
     * @throws ConsentimientoInvalido
     */
    public function otorgar(
        Persona $titular,
        TextoConsentimiento $texto,
        Persona $otorgante,
        string $evidencia = 'clic_firmado',
        ?int $propositoId = null,
        ?string $vigenciaFin = null,
        ?int $mediaId = null,
    ): Consentimiento {
        $relacion = $this->relacionDe($titular, $otorgante);

        /*
         * Se comprueba el hash ANTES de ligar el consentimiento al texto.
         *
         * Si alguien tocó la fila por fuera de la aplicación, el texto ya no
         * es el que se publicó y ligar una firma a él produciría una prueba
         * falsa: diría que la persona aceptó algo que nadie sabe qué era.
         */
        if (! $texto->integroSegunHash()) {
            throw ConsentimientoInvalido::porTextoAlterado($texto->id);
        }

        return DB::transaction(function () use (
            $titular, $texto, $otorgante, $relacion, $evidencia, $propositoId, $vigenciaFin, $mediaId
        ): Consentimiento {
            /*
             * Otorgar de nuevo REEMPLAZA el anterior del mismo tipo y ámbito:
             * se revoca el viejo y nace el nuevo. Dejar los dos vigentes haría
             * imposible saber cuál rige, y revocar uno dejaría el otro
             * amparando lo mismo.
             */
            $this->revocarPrevios($titular, $texto, $propositoId);

            $consentimiento = Consentimiento::query()->create([
                'persona_id' => $titular->id,
                'texto_consentimiento_id' => $texto->id,
                'otorgado_por_persona_id' => $otorgante->id,
                'relacion' => $relacion,
                'organizacion_id' => $texto->organizacion_id ?? $this->contexto->id(),
                'proposito_id' => $propositoId,
                'evidencia' => $evidencia,
                'media_id' => $mediaId,
                'vigencia_inicio' => Carbon::now()->toDateString(),
                'vigencia_fin' => $vigenciaFin,
                'estado' => 'vigente',
            ]);

            /*
             * El desbloqueo es CONSECUENCIA de consentir, no un botón aparte.
             * Si fuera un paso manual, habría expedientes bloqueados para
             * siempre porque a nadie se le ocurrió apretarlo después de que la
             * persona ya re-consintió.
             */
            if ($relacion === 'titular') {
                $this->transicion->levantarBloqueoSiProcede($titular);
            }

            return $consentimiento;
        });
    }

    /**
     * Revoca con EFECTO INMEDIATO.
     *
     * No al día siguiente ni cuando pase el job nocturno: `Consentimiento::
     * estaVigente()` mira `revocado_en`, así que la siguiente decisión de
     * AccesoService ya lo ve cerrado. Es lo que la LFPDPPP entiende por
     * revocación.
     */
    public function revocar(Consentimiento $consentimiento, ?string $motivo = null): Consentimiento
    {
        return DB::transaction(function () use ($consentimiento, $motivo): Consentimiento {
            $consentimiento->update([
                'estado' => 'revocado',
                'revocado_en' => Carbon::now(),
                'motivo_revocacion' => $motivo,
            ]);

            /*
             * Y arrastra las comparticiones que colgaban de él. Aunque
             * ComparticionExpediente::estaVigente() ya lo comprueba en vivo, se
             * marcan también: una compartición que se ve "vigente" en la
             * pantalla de la persona y no funciona es peor que una cerrada.
             */
            ComparticionExpediente::query()
                ->where('consentimiento_id', $consentimiento->id)
                ->whereNull('revocado_en')
                ->update(['revocado_en' => Carbon::now()]);

            return $consentimiento;
        });
    }

    /**
     * La persona abre parte de su historial a otra organización.
     */
    public function compartir(
        Consentimiento $consentimiento,
        int $organizacionDestinoId,
        string $alcance = 'resumen',
        ?int $dominoId = null,
        ?string $vigenciaFin = null,
    ): ComparticionExpediente {
        if (! $consentimiento->estaVigente()) {
            throw ConsentimientoInvalido::porNoEstarVigente();
        }

        return ComparticionExpediente::query()->create([
            'persona_id' => $consentimiento->persona_id,
            'organizacion_destino_id' => $organizacionDestinoId,
            'dominio_id' => $dominoId,
            'consentimiento_id' => $consentimiento->id,
            'alcance' => $alcance,
            'vigencia_fin' => $vigenciaFin,
        ]);
    }

    public function revocarComparticion(ComparticionExpediente $comparticion): ComparticionExpediente
    {
        $comparticion->update(['revocado_en' => Carbon::now()]);

        return $comparticion;
    }

    /**
     * @throws ConsentimientoInvalido
     */
    private function relacionDe(Persona $titular, Persona $otorgante): string
    {
        if ($titular->id === $otorgante->id) {
            return 'titular';
        }

        $esTutorVigente = Tutoria::query()
            ->where('tutor_persona_id', $otorgante->id)
            ->where('menor_persona_id', $titular->id)
            ->vigentes()
            ->exists();

        if (! $esTutorVigente) {
            /*
             * Ni el psicólogo ni el administrador firman por la persona. El
             * consentimiento es un acto del titular o de quien tiene tutela
             * ACREDITADA; si un tercero pudiera otorgarlo, toda la compuerta
             * legal sería decorativa.
             */
            throw ConsentimientoInvalido::porOtorganteSinFacultad();
        }

        return 'tutor';
    }

    private function revocarPrevios(
        Persona $titular,
        TextoConsentimiento $texto,
        ?int $propositoId,
    ): void {
        Consentimiento::query()
            ->where('persona_id', $titular->id)
            ->where('proposito_id', $propositoId)
            ->whereIn('estado', ['vigente', 'pendiente_reconsentimiento'])
            ->whereHas(
                'texto',
                fn ($consulta) => $consulta
                    ->where('tipo_consentimiento_id', $texto->tipo_consentimiento_id)
                    ->where(function ($sub) use ($texto): void {
                        $sub->where('organizacion_id', $texto->organizacion_id);

                        if ($texto->organizacion_id === null) {
                            $sub->orWhereNull('organizacion_id');
                        }
                    })
            )
            ->update([
                'estado' => 'revocado',
                'revocado_en' => Carbon::now(),
                'motivo_revocacion' => 'Reemplazado por un consentimiento posterior.',
            ]);
    }
}
