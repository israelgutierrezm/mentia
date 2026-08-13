<?php

declare(strict_types=1);

namespace App\Domain\Alertas;

use App\Domain\Alertas\Contratos\NotificaAlertas;
use App\Domain\Alertas\Servicios\NotificacionRegistrada;
use Illuminate\Support\ServiceProvider;

/**
 * Alertas — M9.
 *
 * Reactivos centinela, notificación urgente y protocolos escalonados. Un
 * reactivo centinela positivo —ideación suicida en el PHQ-9, el screener
 * C-SSRS— se evalúa de forma síncrona al recibir la respuesta y notifica de
 * inmediato por la cola `alertas`, con la aplicación todavía en curso.
 *
 * Una alerta se cierra con resolución obligatoria, y un tenant no puede
 * habilitar instrumentos con centinelas mientras no registre su protocolo de
 * actuación: encender el detector sin decir quién responde sería peor que no
 * tenerlo.
 *
 * Fase 8.
 */
class AlertasServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [
        /*
         * Fase 6: implementación provisional que sólo deja registro (Doc 08).
         * La Fase 8 cambia ESTA LÍNEA por la notificación real —destinatarios
         * por rol y canal, correo, campana in-app— y AlertaService no se
         * entera. Es para lo que existe el contrato.
         */
        NotificaAlertas::class => NotificacionRegistrada::class,
    ];
}
