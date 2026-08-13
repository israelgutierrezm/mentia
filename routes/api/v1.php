<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgrupacionController;
use App\Http\Controllers\Api\V1\AlcanceController;
use App\Http\Controllers\Api\V1\AlertaController;
use App\Http\Controllers\Api\V1\AplicacionController;
use App\Http\Controllers\Api\V1\ArcoController;
use App\Http\Controllers\Api\V1\AsignacionController;
use App\Http\Controllers\Api\V1\BateriaController;
use App\Http\Controllers\Api\V1\CapturaProtocoloController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\ExpedienteController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Api\V1\ResultadoController;
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

/*
|--------------------------------------------------------------------------
| Motor de aplicación (Doc 07 §5) — SIN sesión
|--------------------------------------------------------------------------
|
| Quien contesta lo hace con su TOKEN de aplicación y puede no tener cuenta:
| un padre que recibe una liga por WhatsApp para responder el M-CHAT sobre su
| hijo no se registra en nada. El token ES la credencial, y el contexto de
| organización lo fija él, no un encabezado que el cliente pueda elegir.
|
| Los límites son DOS y distintos a propósito.
|
| El canje es un endpoint adivinable: quien prueba tokens al azar entra por
| ahí, así que va estrangulado fuerte. Los demás exigen ya el uuid de una
| aplicación existente, y estrangularlos igual castigaría a quien contesta
| rápido —un tamizaje de treinta reactivos en modo infantil son treinta
| interacciones en pocos minutos— hasta sacarlo de su propia evaluación a la
| mitad. Eso no protege nada: sólo abandona instrumentos.
|
*/
Route::post('aplicaciones/iniciar', [AplicacionController::class, 'iniciar'])
    ->middleware('throttle:30,1')
    ->name('aplicaciones.iniciar');

Route::middleware('throttle:180,1')->group(function (): void {
    Route::get('aplicaciones/{aplicacion}/bloques/{clave}/reactivos', [AplicacionController::class, 'reactivos'])
        ->name('aplicaciones.reactivos');
    Route::post('aplicaciones/{aplicacion}/respuestas', [AplicacionController::class, 'respuestas'])
        ->name('aplicaciones.respuestas');
    Route::get('aplicaciones/{aplicacion}/estado', [AplicacionController::class, 'estado'])
        ->name('aplicaciones.estado');
    Route::post('aplicaciones/{aplicacion}/pausar', [AplicacionController::class, 'pausar'])
        ->name('aplicaciones.pausar');
    Route::post('aplicaciones/{aplicacion}/reanudar', [AplicacionController::class, 'reanudar'])
        ->name('aplicaciones.reanudar');
    Route::post('aplicaciones/{aplicacion}/finalizar', [AplicacionController::class, 'finalizar'])
        ->name('aplicaciones.finalizar');
});

/*
 * `dos_factores` va también aquí, y no sólo en web: la API es exactamente por
 * donde un rol de sensibilidad alta podría saltarse el segundo factor si la
 * exigencia viviera nada más en las pantallas.
 */
Route::middleware(['auth:sanctum', 'organizacion', 'dos_factores'])->group(function (): void {
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

    // ── Captura de protocolo (Doc 07 §5) ──────────────────────────────────
    // Con sesión, a diferencia del resto del motor: lo hace un profesional
    // identificado, no quien contesta con una liga.
    Route::post('aplicaciones/protocolo', [CapturaProtocoloController::class, 'store'])
        ->middleware('can:protocolos.capturar')
        ->name('aplicaciones.protocolo');

    // ── Resultados (Doc 07 §6) ────────────────────────────────────────────
    // Sin `can:`: decide AccesoService, y la AUDIENCIA se deriva del rol de
    // quien pregunta. Jamás llega por parámetro (Doc 06 §1).
    Route::get('aplicaciones/{aplicacion}/resultados', [ResultadoController::class, 'show'])
        ->name('resultados.show');
    Route::get('personas/{persona}/perfil-longitudinal', [ResultadoController::class, 'longitudinal'])
        ->name('resultados.longitudinal');
    Route::get('personas/{persona}/comparar-puesto/{perfilPuesto}', [ResultadoController::class, 'compararPuesto'])
        ->name('resultados.comparar-puesto');

    // ── Reportes (Doc 07 §6) ──────────────────────────────────────────────
    // Sin `can:` en la generación individual: decide AccesoService dentro,
    // porque la audiencia y el detalle dependen del rol de quien pide.
    Route::post('reportes/aplicaciones/{aplicacion}', [ReporteController::class, 'individual'])
        ->name('reportes.individual');

    Route::post('reportes/integrador', [ReporteController::class, 'integrador'])
        ->middleware('can:reportes.grupales')->name('reportes.integrador');

    // Grupal y NOM-035: el mismo agregado con distinta portada, y ninguno
    // identifica a nadie. Exigen su propio permiso.
    Route::post('reportes/asignaciones/{asignacion}', [ReporteController::class, 'grupal'])
        ->middleware('can:reportes.grupales')->name('reportes.grupal');

    Route::get('reportes/{reporte}', [ReporteController::class, 'show'])->name('reportes.show');

    Route::post('reportes/{reporte}/validar', [ReporteController::class, 'validarBorrador'])
        ->middleware('can:ia.validar_reportes')->name('reportes.validar');

    Route::post('reportes/{reporte}/firmar', [ReporteController::class, 'firmar'])
        ->middleware('can:ia.validar_reportes')->name('reportes.firmar');

    // La descarga deja bitácora SIEMPRE (Doc 07 §8.5).
    Route::get('reportes/{reporte}/descargar', [ReporteController::class, 'descargar'])
        ->middleware('can:resultados.exportar')->name('reportes.descargar');

    // ── Derechos ARCO (Doc 06 §3) ─────────────────────────────────────────
    Route::middleware('can:arco.gestionar')->group(function (): void {
        Route::get('arco', [ArcoController::class, 'index'])->name('arco.index');
        Route::post('arco', [ArcoController::class, 'store'])->name('arco.store');
        Route::post('arco/{solicitud}/responder', [ArcoController::class, 'responder'])
            ->name('arco.responder');
        Route::get('arco/{solicitud}/exportar', [ArcoController::class, 'exportar'])
            ->name('arco.exportar');
    });

    // ── Alertas (Doc 07 §6) ───────────────────────────────────────────────
    Route::middleware('can:alertas.atender')->group(function (): void {
        Route::get('alertas', [AlertaController::class, 'index'])->name('alertas.index');
        Route::post('alertas/{alerta}/atender', [AlertaController::class, 'atender'])
            ->name('alertas.atender');
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
