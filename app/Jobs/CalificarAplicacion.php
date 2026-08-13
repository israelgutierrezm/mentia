<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Jobs\Calificacion\EtapaAlgoritmos;
use App\Jobs\Calificacion\EtapaBanderas;
use App\Jobs\Calificacion\EtapaBrutos;
use App\Jobs\Calificacion\EtapaInterpretacion;
use App\Jobs\Calificacion\EtapaNormalizacion;
use App\Jobs\Calificacion\EtapaValidez;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Arranca el pipeline de calificación del Doc 05 §2.
 *
 * Las seis etapas van ENCADENADAS, no en paralelo: cada una necesita lo que
 * dejó la anterior. Con `Bus::chain`, si una falla las siguientes no corren, y
 * eso es lo correcto —un resultado normalizado sobre brutos que reventaron a
 * medias es peor que ningún resultado—.
 *
 * Este job no calcula nada: sólo encola. Existe para que quien finaliza una
 * aplicación no tenga que conocer las seis etapas ni su orden, y para que
 * cambiar ese orden sea cambiar una lista.
 *
 * Va en la cola `calificacion` (Doc 02 §7): es el grueso del trabajo y no debe
 * competir con las alertas, que son las que llevan prisa de verdad.
 *
 * Lleva el ID, no el modelo: un job serializado con la aplicación entera
 * guardaría sus atributos en la tabla `jobs`, y ahí no tiene nada que hacer
 * material de un expediente.
 */
class CalificarAplicacion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $aplicacionId)
    {
        $this->onQueue('calificacion');
    }

    public function handle(): void
    {
        $existe = Aplicacion::query()
            ->withoutGlobalScopes()
            ->whereKey($this->aplicacionId)
            ->exists();

        if (! $existe) {
            return;
        }

        Bus::chain($this->etapas($this->aplicacionId))
            ->onQueue('calificacion')
            ->dispatch();
    }

    /**
     * El orden del Doc 05 §2. No es negociable ni configurable: la validez va
     * antes que los brutos porque un protocolo al azar produce puntajes
     * perfectamente calculables, y la normalización después de los algoritmos
     * porque lo que ya clasificó un corte oficial no se re-normaliza.
     *
     * @return list<object>
     */
    public static function etapas(int $aplicacionId): array
    {
        return [
            new EtapaValidez($aplicacionId),
            new EtapaBrutos($aplicacionId),
            new EtapaAlgoritmos($aplicacionId),
            new EtapaNormalizacion($aplicacionId),
            new EtapaInterpretacion($aplicacionId),
            new EtapaBanderas($aplicacionId),
        ];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['calificacion', 'aplicacion:'.$this->aplicacionId];
    }
}
