<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Consentimientos\Modelos\Consentimiento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job nocturno de caducidades (Doc 06 §1, "Caducidad").
 *
 * Marca como vencido lo que ya pasó de fecha: consentimientos y documentos.
 *
 * OJO: esto NO es lo que protege el acceso. `Consentimiento::estaVigente()`
 * comprueba las fechas en vivo, así que un consentimiento vencido a medianoche
 * deja de amparar en la siguiente petición aunque el job no haya corrido. Este
 * job existe para que el ESTADO que ve la persona en su pantalla coincida con
 * la realidad, y para poder consultar "qué venció" sin recalcularlo.
 *
 * Confiar sólo en el job sería el error: una corrida fallida abriría accesos.
 */
class MarcarVencimientos implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $al = null) {}

    public function handle(): void
    {
        $fecha = $this->al !== null ? Carbon::parse($this->al) : Carbon::now();
        $hoy = $fecha->toDateString();

        $consentimientos = Consentimiento::query()
            ->where('estado', 'vigente')
            ->whereNotNull('vigencia_fin')
            ->whereDate('vigencia_fin', '<', $hoy)
            ->update(['estado' => 'vencido']);

        $alcances = DB::table('persona_rol_alcances')
            ->whereNotNull('vigencia_fin')
            ->whereDate('vigencia_fin', '<', $hoy)
            ->count();

        $documentos = DB::table('expediente_documentos')
            ->where('estado', 'validado')
            ->whereNotNull('vigencia_fin')
            ->whereDate('vigencia_fin', '<', $hoy)
            ->count();

        Log::info('Vencimientos marcados', [
            'consentimientos_vencidos' => $consentimientos,
            // Los alcances NO se tocan: su vigencia se resuelve en vivo con
            // scopeVigentes() y reescribirlos borraría la fecha original.
            'alcances_ya_vencidos' => $alcances,
            'documentos_ya_vencidos' => $documentos,
        ]);

        unset($alcances, $documentos);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['mantenimiento', 'vencimientos'];
    }
}
