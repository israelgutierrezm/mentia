<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Eventos;

use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alguien entró a una agrupación.
 *
 * Lo anuncia GestorAgrupaciones y lo escucha Evaluaciones para expandir las
 * asignaciones dinámicas. Va por EVENTO y no con una llamada directa porque el
 * dominio de organizaciones no tiene por qué saber que existen las
 * asignaciones: si mañana algo más quiere reaccionar a un alta —una
 * notificación de bienvenida, un recálculo de cupos— se suscribe y ya.
 */
class PersonaInscritaEnAgrupacion
{
    use Dispatchable;

    public function __construct(public readonly AgrupacionMiembro $miembro) {}
}
