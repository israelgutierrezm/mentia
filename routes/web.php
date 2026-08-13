<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AgrupacionController;
use App\Http\Controllers\Web\AlcanceController;
use App\Http\Controllers\Web\AlertaController;
use App\Http\Controllers\Web\AplicacionController;
use App\Http\Controllers\Web\BateriaController;
use App\Http\Controllers\Web\CapturaProtocoloController;
use App\Http\Controllers\Web\CatalogoController;
use App\Http\Controllers\Web\ConsentimientoController;
use App\Http\Controllers\Web\ExpedienteController;
use App\Http\Controllers\Web\HabilitacionController;
use App\Http\Controllers\Web\MiembroAgrupacionController;
use App\Http\Controllers\Web\PanelController;
use App\Http\Controllers\Web\PersonaController;
use App\Http\Controllers\Web\PortalExpedienteController;
use App\Http\Controllers\Web\ResultadoController;
use App\Http\Controllers\Web\RolController;
use App\Http\Controllers\Web\TutoriaController;
use App\Http\Controllers\Web\UnidadController;
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

/*
 * CONTESTAR. Pública, sin sesión y sin organización activa.
 *
 * Quien recibe una liga por correo puede no tener cuenta en nada: una madre
 * que responde el M-CHAT sobre su hijo no se registra. El token es su
 * credencial y viaja en el FRAGMENTO de la liga (`/contestar#<token>`), que no
 * llega al servidor; el canje lo hace el navegador contra `/api/v1`.
 *
 * Por eso esta ruta no recibe el token como parámetro: si lo hiciera, la
 * credencial quedaría escrita en el log de accesos del servidor web y en cada
 * proxy por el que pase la petición.
 */
Route::get('contestar', [AplicacionController::class, 'contestar'])->name('contestar');

/*
 * PORTAL DEL TITULAR. Sin organización activa a propósito: la persona entra a
 * lo suyo, y su expediente no pertenece a ninguna organización. Exigir aquí un
 * tenant dejaría fuera a quien no está vinculado a ninguno.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('mi-expediente', [PortalExpedienteController::class, 'index'])
        ->name('portal.expediente');
    Route::post('mi-expediente/valores', [PortalExpedienteController::class, 'store'])
        ->name('portal.expediente.store');

    Route::post('consentimientos', [ConsentimientoController::class, 'store'])
        ->name('consentimientos.store');
    Route::post('consentimientos/{consentimiento}/revocar', [ConsentimientoController::class, 'revocar'])
        ->name('consentimientos.revocar');
});

/*
 * Todo lo que toca datos de tenant va detrás de `auth` + `organizacion`.
 * El middleware de organización comprueba que el actor esté vinculado antes
 * de fijar el contexto; sin él, los global scopes no tienen contra qué
 * filtrar y —al fallar cerrado— las pantallas saldrían vacías.
 */
Route::middleware(['auth', 'organizacion'])->group(function (): void {
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

        Route::post('agrupaciones/{agrupacion}/miembros', [MiembroAgrupacionController::class, 'store'])
            ->name('agrupaciones.miembros.store');
        Route::delete('agrupaciones/{agrupacion}/miembros/{miembro}', [MiembroAgrupacionController::class, 'destroy'])
            ->name('agrupaciones.miembros.destroy');
    });

    // ── Personas ──────────────────────────────────────────────────────────
    Route::get('personas', [PersonaController::class, 'index'])
        ->middleware('can:personas.ver')->name('personas.index');
    Route::post('personas', [PersonaController::class, 'store'])
        ->middleware('can:personas.crear')->name('personas.store');

    /*
     * La ficha NO lleva `can:`. Quien decide es AccesoService dentro del
     * controller, porque aquí no basta el permiso: hacen falta también el
     * alcance, la sensibilidad y el consentimiento, y el titular llega a la
     * suya sin ningún permiso.
     */
    Route::get('personas/{persona}', [PersonaController::class, 'show'])->name('personas.show');

    // ── Expediente ────────────────────────────────────────────────────────
    // Sin `can:`: AccesoService decide SECCIÓN POR SECCIÓN.
    Route::get('personas/{persona}/expediente', [ExpedienteController::class, 'show'])
        ->name('expediente.show');
    Route::post('personas/{persona}/expediente/valores', [ExpedienteController::class, 'store'])
        ->middleware('can:expediente.editar')->name('expediente.store');

    // ── Catálogo ──────────────────────────────────────────────────────────
    Route::get('catalogo', [CatalogoController::class, 'index'])->name('catalogo.index');
    Route::get('catalogo/{clave}', [CatalogoController::class, 'show'])->name('catalogo.show');

    Route::middleware('can:instrumentos.habilitar')->group(function (): void {
        Route::get('habilitacion', [HabilitacionController::class, 'index'])
            ->name('habilitacion.index');
        Route::post('habilitacion/{version}/habilitar', [HabilitacionController::class, 'habilitar'])
            ->name('habilitacion.habilitar');
        Route::post('habilitacion/{version}/declaracion', [HabilitacionController::class, 'declararLicencia'])
            ->name('habilitacion.declaracion');
    });

    // ── Baterías ──────────────────────────────────────────────────────────
    Route::middleware('can:baterias.armar')->group(function (): void {
        Route::get('baterias', [BateriaController::class, 'index'])->name('baterias.index');
        Route::post('baterias', [BateriaController::class, 'store'])->name('baterias.store');
        Route::post('baterias/{bateria}/instrumentos', [BateriaController::class, 'agregar'])
            ->name('baterias.agregar');
        Route::delete('baterias/{bateria}/instrumentos/{renglon}', [BateriaController::class, 'quitar'])
            ->name('baterias.quitar');
        Route::post('baterias/{bateria}/orden', [BateriaController::class, 'reordenar'])
            ->name('baterias.orden');
        Route::post('baterias/{bateria}/activar', [BateriaController::class, 'activar'])
            ->name('baterias.activar');
        Route::post('baterias/{bateria}/archivar', [BateriaController::class, 'archivar'])
            ->name('baterias.archivar');
    });

    // ── Alertas ───────────────────────────────────────────────────────────
    Route::middleware('can:alertas.atender')->group(function (): void {
        Route::get('alertas', [AlertaController::class, 'index'])->name('alertas.index');
        Route::post('alertas/{alerta}/atender', [AlertaController::class, 'atender'])
            ->name('alertas.atender');
    });

    // ── Resultados ────────────────────────────────────────────────────────
    // Sin `can:`: decide AccesoService, y la AUDIENCIA se deriva del rol.
    Route::get('aplicaciones/{aplicacion}/resultados', [ResultadoController::class, 'show'])
        ->name('resultados.show');
    Route::get('personas/{persona}/perfil', [ResultadoController::class, 'longitudinal'])
        ->name('resultados.longitudinal');

    // ── Captura de protocolo ──────────────────────────────────────────────
    // Con sesión, a diferencia del resto del motor: lo hace un profesional
    // identificado, no quien contesta con una liga.
    Route::middleware('can:protocolos.capturar')->group(function (): void {
        Route::get('captura-protocolo', [CapturaProtocoloController::class, 'index'])
            ->name('captura-protocolo.index');
        Route::post('captura-protocolo', [CapturaProtocoloController::class, 'store'])
            ->name('captura-protocolo.store');
    });

    // ── Tutorías ──────────────────────────────────────────────────────────
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
        Route::post('roles', [RolController::class, 'store'])->name('roles.store');
        Route::put('roles/{rol}', [RolController::class, 'update'])->name('roles.update');
        Route::delete('roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');

        Route::get('alcances', [AlcanceController::class, 'index'])->name('alcances.index');
        Route::post('alcances', [AlcanceController::class, 'store'])->name('alcances.store');
        Route::delete('alcances/{alcance}', [AlcanceController::class, 'destroy'])
            ->name('alcances.destroy');
    });
});
