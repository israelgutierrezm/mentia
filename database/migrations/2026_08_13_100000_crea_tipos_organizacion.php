<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M1 — tipos_organizacion (catálogo global).
 *
 * El tipo de tenant no cambia el esquema: ajusta el VOCABULARIO de la interfaz
 * y las plantillas precargadas (Doc 02 §3). Una escuela dice "alumnos" y
 * "grupos"; una empresa, "colaboradores" y "vacantes". La misma tabla
 * `agrupaciones` sirve a las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_organizacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 40)->unique();
            $tabla->string('nombre', 80);
            $tabla->string('vocabulario_persona', 40);
            $tabla->string('vocabulario_agrupacion', 40);
            $tabla->sellosDeTiempo();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_organizacion');
    }
};
