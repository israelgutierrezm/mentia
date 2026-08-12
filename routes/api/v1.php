<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\EstadoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Montada en /api/v1 desde bootstrap/app.php, con nombres api.v1.*
| Contrato completo: /docs/07-especificacion-api-v1.md
|
| Cada fase agrega aquí sus endpoints junto con los del módulo web, no
| después (Doc 02 §2, regla 3). Web y API llaman a los mismos servicios de
| dominio; si un endpoint de API necesita lógica que el web no tiene, es que
| la lógica está en el controller y no en el dominio.
|
| Rutas públicas: sólo estado y el canje de token anónimo. Todo lo demás pasa
| por sanctum y, cuando toca datos de una persona, por AccesoService.
|
*/

Route::get('estado', EstadoController::class)->name('estado');

/*
 * Fase 1 en adelante. El encabezado X-Organizacion selecciona el tenant
 * activo y lo resuelve el middleware de tenant (Doc 07 §1).
 *
 * Route::middleware(['auth:sanctum', 'organizacion'])->group(function (): void {
 *     ...
 * });
 */
