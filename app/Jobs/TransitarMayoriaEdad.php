<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Consentimientos\Servicios\TransicionMayoriaEdad;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job diario de mayoría de edad.
 *
 * Al cumplir 18 años: tutorías extintas, consentimientos de tutor pendientes de
 * re-consentimiento y expediente bloqueado para terceros (Doc 06 §3).
 */
class TransitarMayoriaEdad implements ShouldQueue
{
    use Queueable;

    public function handle(TransicionMayoriaEdad $transicion): void
    {
        $cuantas = $transicion->correr();

        if ($cuantas > 0) {
            Log::info('Transición de mayoría de edad', ['personas' => $cuantas]);
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['mantenimiento', 'mayoria-edad'];
    }
}
