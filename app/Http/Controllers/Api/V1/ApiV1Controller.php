<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Base de los controllers de la API v1.
 *
 * Existe para fijar el contrato compartido del Doc 07 en un solo lugar: JSON
 * UTF-8, fechas ISO 8601 con zona, paginación por cursor (`?cursor=&limit=`) y
 * errores RFC 7807 —estos últimos los emite App\Http\Api\Problema desde el
 * manejador de excepciones, así que un controller nunca arma un error a mano—.
 *
 * Los controllers web y los de la API llaman a LOS MISMOS servicios de
 * dominio; lo único que cambia es cómo se devuelve el resultado.
 */
abstract class ApiV1Controller extends Controller
{
    /**
     * Tope de elementos por página. El cliente puede pedir menos con `limit`,
     * nunca más: sin tope, un `limit=100000` contra `respuestas` —decenas de
     * millones de filas proyectadas (Doc 02 §7)— tumba la base.
     */
    protected const LIMITE_MAXIMO = 100;

    protected const LIMITE_POR_OMISION = 25;

    protected function limite(int $solicitado): int
    {
        if ($solicitado < 1) {
            return self::LIMITE_POR_OMISION;
        }

        return min($solicitado, self::LIMITE_MAXIMO);
    }
}
