<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgrupacionController;
use App\Http\Controllers\Api\V1\AlcanceController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\TutoriaController;
use App\Http\Controllers\Api\V1\UnidadController;
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
| El encabezado `X-Organizacion` selecciona el tenant activo (Doc 07 §1) y lo
| resuelve el middleware `organizacion`, que además comprueba que el actor
| esté vinculado a ella.
|
*/

Route::get('estado', EstadoController::class)->name('estado');

Route::middleware(['auth:sanctum', 'organizacion'])->group(function (): void {
    // ── Organización ──────────────────────────────────────────────────────
    Route::middleware('can:unidades.gestionar')->group(function (): void {
        Route::get('unidades', [UnidadController::class, 'index'])->name('unidades.index');
        Route::post('unidades', [UnidadController::class, 'store'])->name('unidades.store');
        Route::put('unidades/{unidad}', [UnidadController::class, 'update'])->name('unidades.update');
    });

    Route::middleware('can:agrupaciones.gestionar')->group(function (): void {
        Route::get('agrupaciones', [AgrupacionController::class, 'index'])->name('agrupaciones.index');
        Route::post('agrupaciones', [AgrupacionController::class, 'store'])->name('agrupaciones.store');
        Route::put('agrupaciones/{agrupacion}', [AgrupacionController::class, 'update'])
            ->name('agrupaciones.update');

        Route::post('agrupaciones/{agrupacion}/miembros', [AgrupacionController::class, 'inscribir'])
            ->name('agrupaciones.miembros.store');
        Route::delete('agrupaciones/{agrupacion}/miembros/{miembro}', [AgrupacionController::class, 'darDeBaja'])
            ->name('agrupaciones.miembros.destroy');
    });

    // ── Personas ──────────────────────────────────────────────────────────
    Route::get('personas', [PersonaController::class, 'index'])
        ->middleware('can:personas.ver')->name('personas.index');
    Route::post('personas', [PersonaController::class, 'store'])
        ->middleware('can:personas.crear')->name('personas.store');

    // Sin `can:`: decide AccesoService con las cuatro dimensiones.
    Route::get('personas/{persona}', [PersonaController::class, 'show'])->name('personas.show');

    // ── Tutorías (Doc 07 §2) ──────────────────────────────────────────────
    Route::middleware('can:tutorias.validar')->group(function (): void {
        Route::get('tutorias', [TutoriaController::class, 'index'])->name('tutorias.index');
        Route::post('tutorias', [TutoriaController::class, 'store'])->name('tutorias.store');
        Route::post('tutorias/{tutoria}/validar', [TutoriaController::class, 'validar'])
            ->name('tutorias.validar');
        Route::post('tutorias/{tutoria}/revocar', [TutoriaController::class, 'revocar'])
            ->name('tutorias.revocar');
    });

    // ── Accesos ───────────────────────────────────────────────────────────
    Route::middleware('can:roles.gestionar')->group(function (): void {
        Route::get('roles', [RolController::class, 'index'])->name('roles.index');
        Route::get('roles/catalogo-permisos', [RolController::class, 'catalogo'])
            ->name('roles.catalogo');
        Route::post('roles', [RolController::class, 'store'])->name('roles.store');
        Route::put('roles/{rol}', [RolController::class, 'update'])->name('roles.update');
        Route::delete('roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');

        Route::get('alcances', [AlcanceController::class, 'index'])->name('alcances.index');
        Route::post('alcances', [AlcanceController::class, 'store'])->name('alcances.store');
        Route::delete('alcances/{alcance}', [AlcanceController::class, 'destroy'])
            ->name('alcances.destroy');
    });
});
