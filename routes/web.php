<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web (Inertia)
|--------------------------------------------------------------------------
|
| Controllers delgados en App\Http\Controllers\Web: reciben la petición,
| llaman a un servicio de app/Domain/ y devuelven una página Inertia.
|
| Su espejo en API vive en routes/api/v1.php y llama a los MISMOS servicios.
|
*/

Route::get('/', PanelController::class)->name('panel');
