<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Alertas\Contratos\NotificaAlertas;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Evaluaciones\Datos\DisparoCentinela;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera alertas. La parte de la Fase 6; la notificación real llega en la
 * Fase 8.
 *
 * La alerta se ESCRIBE siempre, aunque la notificación falle. Son dos actos
 * separados a propósito: si notificar y registrar fueran lo mismo, un problema
 * de correo haría desaparecer el rastro de que hubo un riesgo detectado — y ese
 * rastro es lo que se le enseña a quien pregunte qué hizo el sistema.
 */
class AlertaService
{
    public function __construct(private readonly NotificaAlertas $notificador) {}

    /**
     * Alerta crítica por reactivo centinela.
     *
     * Se llama SÍNCRONO desde la recepción del lote, con la aplicación en
     * curso.
     */
    public function porCentinela(Aplicacion $aplicacion, DisparoCentinela $disparo): Alerta
    {
        $alerta = DB::transaction(fn (): Alerta => Alerta::query()->create([
            'organizacion_id' => $aplicacion->organizacion_id,

            /*
             * NULL en aplicaciones anónimas. Hay alerta —el riesgo existe y
             * queda registrado— pero no hay a quién atribuirla. Es el precio
             * del anonimato, y está asumido: en una NOM-035 anónima el
             * protocolo del tenant es dirigirse al centro de trabajo, no a una
             * persona.
             */
            'persona_id' => $aplicacion->persona_id,

            'aplicacion_id' => $aplicacion->id,
            'tipo' => 'centinela',
            'severidad' => $disparo->severidad(),
            'reactivo_id' => $disparo->respuesta->reactivo_id,
            'mensaje' => $disparo->mensaje(),
            'estado' => 'nueva',
            'creada_en' => Carbon::now(),
        ]));

        $this->notificador->notificar($alerta);

        return $alerta;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Alerta>
     */
    public function abiertasDe(int $organizacionId): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, Alerta> */
        return Alerta::query()
            ->where('organizacion_id', $organizacionId)
            ->abiertas()
            ->with(['persona', 'reactivo'])
            ->orderByRaw("FIELD(severidad, 'critica', 'alta', 'media')")
            ->orderByDesc('creada_en')
            ->get();
    }
}
