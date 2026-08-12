<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Base de todos los controllers.
 *
 * LOS CONTROLLERS NO CONTIENEN LÓGICA DE NEGOCIO (Doc 02 §2, regla 1).
 * Reciben la petición, la validan con un FormRequest, llaman a un servicio de
 * `app/Domain/` y devuelven una página Inertia o un API Resource. Si un método
 * necesita saber qué es un baremo o cuándo vence un consentimiento, esa
 * decisión está en el lugar equivocado.
 */
abstract class Controller
{
    //
}
