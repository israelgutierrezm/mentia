<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Servicios\RegistroRespuestas;

/**
 * Una aplicación con un reactivo centinela, lista para dispararlo.
 *
 * El centinela se agrega DESPUÉS de crear la asignación a propósito: así estas
 * pruebas miden la notificación y el cierre de la alerta sin chocar contra la
 * compuerta del protocolo de actuación, que tiene sus propias pruebas.
 */
class EscenarioCentinela
{
    public Aplicacion $aplicacion;

    private EscenarioAplicacion $base;

    public function __construct(public EscenarioTenant $tenant)
    {
        $this->base = new EscenarioAplicacion($tenant);
    }

    /**
     * Contesta el centinela con un valor de riesgo y devuelve la alerta.
     */
    public function dispararCentinela(): Alerta
    {
        $reactivo = $this->base->centinela();
        $this->aplicacion = $this->base->iniciar();

        app(RegistroRespuestas::class)->recibir($this->aplicacion, [
            EscenarioAplicacion::respuesta($reactivo->codigo, 3),
        ]);

        $alerta = Alerta::query()
            ->where('aplicacion_id', $this->aplicacion->id)
            ->latest('id')
            ->firstOrFail();

        return $alerta;
    }
}
