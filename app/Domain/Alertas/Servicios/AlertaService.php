<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Alertas\Contratos\NotificaAlertas;
use App\Domain\Alertas\Excepciones\AlertaSinResolucion;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Evaluaciones\Datos\DisparoCentinela;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera, notifica y cierra alertas.
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
     * Alerta por bandera de resultado o por validez, desde el pipeline.
     */
    public function porResultado(
        Aplicacion $aplicacion,
        string $tipo,
        string $severidad,
        string $mensaje,
    ): Alerta {
        $alerta = Alerta::query()->create([
            'organizacion_id' => $aplicacion->organizacion_id,
            'persona_id' => $aplicacion->persona_id,
            'aplicacion_id' => $aplicacion->id,
            'tipo' => $tipo,
            'severidad' => $severidad,
            'mensaje' => mb_substr($mensaje, 0, 255),
            'estado' => 'nueva',
            'creada_en' => Carbon::now(),
        ]);

        $this->notificador->notificar($alerta);

        return $alerta;
    }

    /**
     * Cierra una alerta. LA RESOLUCIÓN ES OBLIGATORIA (Doc 06 §5).
     *
     * No es formalismo: una alerta que se puede cerrar con un clic se cierra
     * con un clic, y entonces el registro no dice si alguien habló con la
     * persona o si sólo quitaron el punto rojo de la pantalla. Lo que la
     * organización tiene que poder demostrar es lo segundo, no lo primero.
     *
     * @throws AlertaSinResolucion
     */
    public function atender(Alerta $alerta, Persona $quienAtiende, string $resolucion): Alerta
    {
        $resolucion = trim($resolucion);

        if (mb_strlen($resolucion) < 20) {
            throw AlertaSinResolucion::porSerDemasiadoBreve();
        }

        if ($alerta->estado === 'cerrada') {
            throw AlertaSinResolucion::porYaEstarCerrada();
        }

        $alerta->update([
            'estado' => 'cerrada',
            'atendida_por' => $quienAtiende->id,
            'atendida_en' => Carbon::now(),
            'resolucion' => $resolucion,
        ]);

        return $alerta->refresh();
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
