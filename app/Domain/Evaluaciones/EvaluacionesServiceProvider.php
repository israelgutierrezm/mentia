<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones;

use App\Domain\Evaluaciones\Contratos\CanalNotificacion;
use App\Domain\Evaluaciones\Eventos\PersonaInscritaEnAgrupacion;
use App\Domain\Evaluaciones\Servicios\CanalCorreo;
use App\Domain\Evaluaciones\Servicios\ExpansorAsignacionesDinamicas;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Evaluaciones — M6, M7 y M8.
 *
 * El evento que se acumula en la línea de tiempo de la persona, de punta a
 * punta: asignaciones (individuales, grupales y campañas) y baterías; el
 * motor de aplicación (sesiones, entrega parcelada, respuestas idempotentes
 * por lotes, cronómetros server-side, reanudación); y el pipeline de
 * calificación en seis etapas —validez, brutos, algoritmos especiales,
 * normalización, interpretación, banderas—.
 *
 * El pipeline corre en la cola `calificacion` como jobs encadenados. La única
 * parte síncrona es la evaluación de reactivos centinela al recibir
 * respuestas, porque una alerta que espera en cola no es una alerta
 * (Doc 02 §2, regla 4).
 *
 * Fases 5, 6 y 7.
 */
class EvaluacionesServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [
        /*
         * V1: sólo correo. WhatsApp y push entran cambiando esta línea —el
         * Doc 01 §4 describe padres respondiendo el M-CHAT por WhatsApp—, y
         * los servicios de asignación no se enteran.
         */
        CanalNotificacion::class => CanalCorreo::class,
    ];

    public function boot(): void
    {
        /*
         * Alta en agrupación → expansión de asignaciones dinámicas.
         *
         * Aquí y no en un EventServiceProvider global: la suscripción
         * pertenece al dominio que reacciona, así que se lee junto al código
         * que la atiende.
         */
        Event::listen(PersonaInscritaEnAgrupacion::class, ExpansorAsignacionesDinamicas::class);
    }
}
