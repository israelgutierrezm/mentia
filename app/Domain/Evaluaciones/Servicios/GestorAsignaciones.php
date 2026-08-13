<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Excepciones\AsignacionInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operación del día a día sobre una asignación ya creada: exentar, cerrar,
 * cancelar y consultar el avance.
 */
class GestorAsignaciones
{
    public function __construct(private readonly GestorTokens $tokens) {}

    /**
     * Exenta a una persona con MOTIVO obligatorio.
     *
     * El motivo no es burocracia: una asignación de NOM-035 con veinte
     * exentos y sin explicación es exactamente lo que un inspector pregunta.
     *
     * @throws AsignacionInvalida
     */
    public function exentar(AsignacionDestinatario $destinatario, string $motivo): AsignacionDestinatario
    {
        if (trim($motivo) === '') {
            throw new AsignacionInvalida('La exención necesita un motivo.');
        }

        if ($destinatario->yaContesto()) {
            throw new AsignacionInvalida(
                'Esa persona ya contestó: exentarla ahora borraría el sentido de su respuesta.'
            );
        }

        $destinatario->update([
            'estado' => 'exenta',
            'motivo_exencion' => trim($motivo),

            // Se le retira la liga: exentar y dejar el token vivo permitiría
            // contestar igual.
            'token_expira_en' => Carbon::now()->subSecond(),
        ]);

        return $destinatario;
    }

    /**
     * Cierra la asignación. Los pendientes quedan `expirada`.
     *
     * @throws AsignacionInvalida
     */
    public function cerrar(Asignacion $asignacion): Asignacion
    {
        $this->exigirActiva($asignacion);

        return DB::transaction(function () use ($asignacion): Asignacion {
            $asignacion->update(['estado' => 'cerrada']);

            /*
             * Invalidar los tokens es lo que hace real el cierre. Sin esto,
             * una liga enviada hace tres días seguiría abriendo la evaluación
             * después de cerrada, y los resultados de una campaña con fecha de
             * corte dejarían de ser comparables.
             */
            $this->tokens->invalidarDe($asignacion);

            AsignacionDestinatario::query()
                ->where('asignacion_id', $asignacion->id)
                ->pendientes()
                ->update(['estado' => 'expirada']);

            return $asignacion;
        });
    }

    /**
     * @throws AsignacionInvalida
     */
    public function cancelar(Asignacion $asignacion): Asignacion
    {
        $this->exigirActiva($asignacion);

        return DB::transaction(function () use ($asignacion): Asignacion {
            $asignacion->update(['estado' => 'cancelada']);
            $this->tokens->invalidarDe($asignacion);

            return $asignacion;
        });
    }

    /**
     * Avance de la asignación.
     *
     * Si es ANÓNIMA devuelve SÓLO CONTEOS. No es una decisión de interfaz: en
     * una NOM-035 anónima, saber quién ya contestó y quién no permite deducir
     * de quién es cada respuesta en un centro de trabajo chico, y eso destruye
     * el anonimato que hace que la gente conteste con la verdad.
     *
     * @return array<string, mixed>
     */
    public function avance(Asignacion $asignacion): array
    {
        $porEstado = AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        $total = array_sum($porEstado);
        $completadas = $porEstado['completada'] ?? 0;

        return [
            'folio' => $asignacion->folio,
            'estado' => $asignacion->estado,
            'es_anonima' => $asignacion->es_anonima,
            'ventana_abierta' => $asignacion->ventanaAbierta(),
            'total' => $total,
            'completadas' => $completadas,
            'porcentaje' => $total > 0 ? round($completadas / $total * 100, 1) : 0.0,
            'por_estado' => $porEstado,
        ];
    }

    /**
     * El detalle por persona. NUNCA para una asignación anónima.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AsignacionInvalida
     */
    public function destinatariosDetallados(Asignacion $asignacion): array
    {
        if ($asignacion->es_anonima) {
            throw AsignacionInvalida::porSerAnonima();
        }

        return AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->with(['persona', 'quienResponde'])
            ->get()
            ->map(fn (AsignacionDestinatario $destinatario): array => [
                'id' => $destinatario->id,
                'persona_uuid' => $destinatario->persona->uuid,
                'persona' => $destinatario->persona->nombreCompleto(),
                'quien_responde' => $destinatario->quienResponde?->nombreCompleto(),
                'estado' => $destinatario->estado,
                'notificada_en' => $destinatario->notificada_en?->toIso8601String(),
                'recordatorios' => $destinatario->recordatorios_enviados,
                'motivo_exencion' => $destinatario->motivo_exencion,
            ])
            ->values()
            ->all();
    }

    /**
     * @throws AsignacionInvalida
     */
    private function exigirActiva(Asignacion $asignacion): void
    {
        if (! $asignacion->estaActiva()) {
            throw AsignacionInvalida::porNoEstarActiva($asignacion->estado);
        }
    }
}
