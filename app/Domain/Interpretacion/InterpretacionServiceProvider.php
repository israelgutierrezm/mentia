<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion;

use Illuminate\Support\ServiceProvider;

/**
 * Interpretación — M10.
 *
 * Convierte resultados en texto: reglas de interpretación con condiciones y
 * variables resueltas, perfiles tipo, comparadores (contra perfil de puesto,
 * contra sí misma en el tiempo, contra el grupo) y reportes por audiencia.
 *
 * El sistema SUGIERE, NUNCA DIAGNOSTICA (principio P6). Toda salida se
 * redacta como "perfil compatible con" o "se sugiere canalización"; el
 * diagnóstico y la firma son actos del profesional humano. Los reportes
 * integradores de IA nacen como borrador y exigen validación y firma antes de
 * liberarse.
 *
 * Fases 7 y 9.
 */
class InterpretacionServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
