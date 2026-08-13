<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgrupacionController;
use App\Http\Controllers\Api\V1\AlcanceController;
use App\Http\Controllers\Api\V1\AsignacionController;
use App\Http\Controllers\Api\V1\BateriaController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\ExpedienteController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\TenantInstrumentoController;
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

    // ── Expediente (Doc 07 §2) ────────────────────────────────────────────
    // Sin `can:`: AccesoService decide sección por sección.
    Route::get('expedientes/{persona}', [ExpedienteController::class, 'show'])
        ->name('expedientes.show');
    Route::put('expedientes/{persona}/valores', [ExpedienteController::class, 'guardarValores'])
        ->name('expedientes.valores');
    Route::get('expedientes/{persona}/pendientes', [ExpedienteController::class, 'pendientes'])
        ->middleware('can:expediente.validar')->name('expedientes.pendientes');

    // ── Tutorías (Doc 07 §2) ──────────────────────────────────────────────
    Route::middleware('can:tutorias.validar')->group(function (): void {
        Route::get('tutorias', [TutoriaController::class, 'index'])->name('tutorias.index');
        Route::post('tutorias', [TutoriaController::class, 'store'])->name('tutorias.store');
        Route::post('tutorias/{tutoria}/validar', [TutoriaController::class, 'validar'])
            ->name('tutorias.validar');
        Route::post('tutorias/{tutoria}/revocar', [TutoriaController::class, 'revocar'])
            ->name('tutorias.revocar');
    });

    // ── Catálogo (Doc 07 §3) ──────────────────────────────────────────────
    // La ficha NUNCA incluye reactivos: el contenido sale parcelado durante la
    // aplicación (Doc 06 §3, protección de contenido).
    Route::get('catalogo/instrumentos', [CatalogoController::class, 'index'])
        ->name('catalogo.instrumentos.index');
    Route::get('catalogo/instrumentos/{clave}', [CatalogoController::class, 'show'])
        ->name('catalogo.instrumentos.show');

    Route::middleware('can:instrumentos.habilitar')->group(function (): void {
        Route::get('tenant/instrumentos', [TenantInstrumentoController::class, 'index'])
            ->name('tenant.instrumentos.index');
        Route::post('tenant/instrumentos/{version}/habilitar', [TenantInstrumentoController::class, 'habilitar'])
            ->name('tenant.instrumentos.habilitar');
        Route::post('tenant/instrumentos/{version}/declaracion-licencia', [TenantInstrumentoController::class, 'declararLicencia'])
            ->name('tenant.instrumentos.declaracion');
    });

    Route::post('tenant/instrumentos/{version}/contenido/importar', [TenantInstrumentoController::class, 'importarContenido'])
        ->middleware('can:instrumentos.capturar_contenido')
        ->name('tenant.instrumentos.importar');

    // ── Asignaciones (Doc 07 §4) ──────────────────────────────────────────
    // Por FOLIO, no por id: es lo que se dicta por teléfono y lo que un
    // integrador guarda en su sistema.
    Route::middleware('can:evaluaciones.asignar')->group(function (): void {
        Route::get('asignaciones', [AsignacionController::class, 'index'])
            ->name('asignaciones.index');
        Route::get('asignaciones/{asignacion}', [AsignacionController::class, 'show'])
            ->name('asignaciones.show');
        Route::get('asignaciones/{asignacion}/destinatarios', [AsignacionController::class, 'destinatarios'])
            ->name('asignaciones.destinatarios');
        Route::post('asignaciones/{asignacion}/recordatorios', [AsignacionController::class, 'recordatorios'])
            ->name('asignaciones.recordatorios');
        Route::post('asignaciones/{asignacion}/cerrar', [AsignacionController::class, 'cerrar'])
            ->name('asignaciones.cerrar');
        Route::post('asignaciones/{asignacion}/cancelar', [AsignacionController::class, 'cancelar'])
            ->name('asignaciones.cancelar');
        Route::post('asignaciones/{asignacion}/destinatarios/{destinatario}/exentar', [AsignacionController::class, 'exentar'])
            ->name('asignaciones.exentar');
    });

    // El `can:` de la creación lo resuelve el FormRequest: una asignación
    // discreta exige su propio permiso.
    Route::post('asignaciones', [AsignacionController::class, 'store'])
        ->name('asignaciones.store');

    // ── Baterías (Doc 07 §3) ──────────────────────────────────────────────
    Route::middleware('can:baterias.armar')->group(function (): void {
        Route::get('baterias', [BateriaController::class, 'index'])->name('baterias.index');
        Route::post('baterias', [BateriaController::class, 'store'])->name('baterias.store');
        Route::put('baterias/{bateria}', [BateriaController::class, 'update'])->name('baterias.update');
        Route::post('baterias/{bateria}/instrumentos', [BateriaController::class, 'agregar'])
            ->name('baterias.agregar');
        Route::delete('baterias/{bateria}/instrumentos/{renglon}', [BateriaController::class, 'quitar'])
            ->name('baterias.quitar');
        Route::put('baterias/{bateria}/orden', [BateriaController::class, 'reordenar'])
            ->name('baterias.orden');
        Route::post('baterias/{bateria}/activar', [BateriaController::class, 'activar'])
            ->name('baterias.activar');
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
