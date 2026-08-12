<?php

declare(strict_types=1);

namespace App\Domain\Catalogo;

use Illuminate\Support\ServiceProvider;

/**
 * Catálogo — M5.
 *
 * Los instrumentos descritos como configuración, nunca como código
 * (principio P3): categorías, instrumentos, versiones inmutables tras
 * publicarse, escalas, bloques, reactivos, opciones, claves, baremos en capas
 * y reglas de interpretación. Agregar una prueba nueva es cargar
 * configuración.
 *
 * El catálogo es GLOBAL (sin organizacion_id), salvo el contenido licenciado:
 * los reactivos que un tenant captura bajo su propia licencia llevan
 * `organizacion_id_contenido` y jamás se sirven a otro tenant (Doc 02 §3).
 *
 * Fase 3.
 */
class CatalogoServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];
}
