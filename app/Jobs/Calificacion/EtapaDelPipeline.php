<?php

declare(strict_types=1);

namespace App\Jobs\Calificacion;

use App\Domain\Catalogo\Modelos\EtapaPipeline;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;
use App\Domain\Interpretacion\Servicios\RegistroEstrategias;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lo que comparten las seis etapas del Doc 05 §2.
 *
 * CADA ETAPA ES UN JOB APARTE y reconstruye su contexto desde la base. No se
 * pasan datos entre jobs, y eso no es rodeo:
 *
 * - Un job encolado con las respuestas dentro las dejaría escritas en la tabla
 *   `jobs` y en los paneles de Horizon, que es material de expediente clínico
 *   fuera de su lugar.
 * - Cada etapa persiste su salida (Doc 05 §1.2), así que la base ya es la
 *   fuente. Es lo que permite recalificar desde la etapa cuatro sin volver a
 *   sumar, y reconstruir el camino bruto → normalizado → interpretación ante
 *   una impugnación.
 *
 * El job lleva el ID, nunca el modelo, por la misma razón.
 */
abstract class EtapaDelPipeline implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $aplicacionId)
    {
        $this->onQueue('calificacion');
    }

    /** Qué etapa es: `validez`, `brutos`, … */
    abstract protected function etapa(): string;

    abstract protected function procesar(ContextoCalificacion $contexto): void;

    public function handle(): void
    {
        $aplicacion = $this->aplicacion();

        if ($aplicacion === null) {
            return;
        }

        /*
         * Una aplicación declarada INVÁLIDA no sigue el pipeline (Doc 05 §2,
         * etapa 1). Calcular sus puntajes produciría números que se ven como
         * resultados: una escala en 4 sobre 27 por haber contestado tres
         * reactivos no es un puntaje bajo, es un protocolo incompleto, y a esa
         * altura ya nadie lo distingue.
         *
         * La verificación va en cada etapa y no sólo en la primera porque las
         * seis son jobs sueltos en la cola: cualquiera puede encolarse por su
         * cuenta desde una recalificación.
         */
        $detiene = (bool) config('mentia.calificacion.detener_si_invalida', true);

        if ($detiene && $aplicacion->validez === 'invalida' && $this->etapa() !== 'validez') {
            return;
        }

        $this->procesar(ContextoCalificacion::para($aplicacion));
    }

    protected function aplicacion(): ?Aplicacion
    {
        /*
         * `withoutGlobalScopes`: la cola no tiene organización activa y el
         * scope de tenant falla CERRADO. Sin esto el job no encontraría nunca
         * su aplicación y el pipeline se quedaría callado.
         */
        return Aplicacion::query()
            ->withoutGlobalScopes()
            ->with('version.instrumento')
            ->find($this->aplicacionId);
    }

    /**
     * Las estrategias configuradas para esta etapa, en orden.
     *
     * @return list<array{estrategia: \App\Domain\Interpretacion\Contratos\EstrategiaCalificacion, parametros: array<string, string>}>
     */
    protected function estrategiasConfiguradas(ContextoCalificacion $contexto): array
    {
        $registro = app(RegistroEstrategias::class);

        $filas = EtapaPipeline::query()
            ->where('version_instrumento_id', $contexto->aplicacion->version_instrumento_id)
            ->where('etapa', $this->etapa())
            ->where('activa', true)
            ->with('parametros')
            ->orderBy('orden')
            ->get();

        $resueltas = [];

        foreach ($filas as $fila) {
            $resueltas[] = [
                'estrategia' => $registro->resolverParaEtapa($fila->estrategia_clave, $this->etapa()),
                'parametros' => $fila->parametrosComoMapa(),
            ];
        }

        return $resueltas;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['calificacion', 'etapa:'.$this->etapa(), 'aplicacion:'.$this->aplicacionId];
    }
}
