<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M3 — plantillas_rol (global) y sus permisos.
 *
 * El molde, no el rol. Al crear un tenant, las plantillas de su
 * tipo_organizacion se CLONAN a roles Spatie propios de esa organización
 * (Doc 03 §M3). A partir de ahí cada organización edita los suyos y puede
 * borrarlos: los roles de ejemplo son borrables por diseño, y ningún código
 * debe buscarlos por nombre.
 *
 * Se clona en vez de apuntar a la plantilla porque, si se apuntara, corregir
 * una plantilla global cambiaría los permisos efectivos de todos los tenants
 * en producción sin que ninguno lo pidiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_rol', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = aplica a cualquier tipo de organización (Titular, Tutor,
            // Auditor). Con valor = sólo a ese tipo.
            $tabla->foreignId('tipo_organizacion_id')->nullable()
                ->constrained('tipos_organizacion')->cascadeOnDelete();

            $tabla->string('clave', 60);
            $tabla->string('nombre', 80);
            $tabla->unsignedTinyInteger('nivel_sensibilidad_max')->default(1);
            $tabla->sellosDeTiempo();

            $tabla->unique(['tipo_organizacion_id', 'clave']);
        });

        Schema::create('plantilla_rol_permisos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('plantilla_rol_id')
                ->constrained('plantillas_rol')->cascadeOnDelete();

            /*
             * El permiso va por NOMBRE y no por FK a `permissions`. Los
             * permisos son catálogo fijo del código (Doc 03 §M3): una llave
             * que el código consulta. Con FK, sembrar plantillas exigiría que
             * los permisos ya existieran en ese orden exacto, y renombrar uno
             * dejaría filas apuntando a un id que ya significa otra cosa.
             */
            $tabla->string('permiso', 80);
            $tabla->sellosDeTiempo();

            $tabla->unique(['plantilla_rol_id', 'permiso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_rol_permisos');
        Schema::dropIfExists('plantillas_rol');
    }
};
