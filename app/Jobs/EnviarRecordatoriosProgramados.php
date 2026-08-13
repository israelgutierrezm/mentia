<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Evaluaciones\Servicios\RecordatorioProgramado;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job diario de recordatorios.
 *
 * Va en la cola `notificaciones` (Doc 02 §7): tolera reintentos y no debe
 * competir con el pipeline de calificación ni con las alertas.
 *
 * Los tokens se generan DENTRO del job, no antes: así el claro nunca viaja en
 * el payload ni queda escrito en la tabla `jobs`.
 */
class EnviarRecordatoriosProgramados implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notificaciones');
    }

    public function handle(RecordatorioProgramado $recordatorio): void
    {
        $enviados = $recordatorio->correr();

        if ($enviados > 0) {
            Log::info('Recordatorios programados enviados', ['total' => $enviados]);
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['notificaciones', 'recordatorios'];
    }
}
