<?php

declare(strict_types=1);

namespace App\Domain\Expedientes;

use Illuminate\Support\ServiceProvider;

/**
 * Expedientes — M4.
 *
 * El expediente psicométrico de vida: secciones y campos config-driven (una
 * fila por campo, una fila por valor, versionado con estados de validación),
 * documentos tipificados sobre medialibrary y notas profesionales cifradas.
 *
 * Es donde se acumula la línea de tiempo de la persona; los resultados
 * normalizados de cada evaluación aterrizan aquí por dominio (cognitivo,
 * emocional, personalidad, intereses, adaptativo).
 *
 * Fase 2.
 */
class ExpedientesServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
