<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Excepciones\EstrategiaDesconocida;

/**
 * El `ScoringStrategyRegistry` del Doc 05 §2.
 *
 * Resuelve una clave del catálogo —`suma_ponderada`, `nom035_cortes`— a la
 * clase que la implementa. Que sea un registro y no un `match` es lo que
 * permite que un instrumento nuevo con una lógica propia se sume sin abrir el
 * pipeline: se escribe la clase, se registra y ya.
 *
 * Falla RUIDOSO ante una clave desconocida. La alternativa —ignorar la etapa y
 * seguir— produciría una calificación incompleta que se ve terminada: escalas
 * en cero que parecen puntajes bajos, y nadie mirando.
 */
class RegistroEstrategias
{
    /** @var array<string, EstrategiaCalificacion> */
    private array $estrategias = [];

    public function registrar(EstrategiaCalificacion $estrategia): void
    {
        $this->estrategias[$estrategia::clave()] = $estrategia;
    }

    /**
     * @throws EstrategiaDesconocida
     */
    public function resolver(string $clave): EstrategiaCalificacion
    {
        if (! isset($this->estrategias[$clave])) {
            throw EstrategiaDesconocida::para($clave, array_keys($this->estrategias));
        }

        return $this->estrategias[$clave];
    }

    /**
     * @throws EstrategiaDesconocida
     */
    public function resolverParaEtapa(string $clave, string $etapa): EstrategiaCalificacion
    {
        $estrategia = $this->resolver($clave);

        if ($estrategia::etapa() !== $etapa) {
            /*
             * Una estrategia de brutos configurada en la etapa de normalización
             * no "hace algo raro": corre con un contexto que no tiene lo que
             * espera y produce un número. Mejor que no arranque.
             */
            throw EstrategiaDesconocida::porEtapaEquivocada($clave, $etapa, $estrategia::etapa());
        }

        return $estrategia;
    }

    public function conoce(string $clave): bool
    {
        return isset($this->estrategias[$clave]);
    }

    /**
     * @return list<string>
     */
    public function clavesDe(string $etapa): array
    {
        $claves = [];

        foreach ($this->estrategias as $clave => $estrategia) {
            if ($estrategia::etapa() === $etapa) {
                $claves[] = $clave;
            }
        }

        return $claves;
    }
}
