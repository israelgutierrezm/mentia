<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M2 — organizacion_personas (vinculación persona ↔ tenant).
 *
 * Es la tabla donde el expediente de vida se cruza con el multi-tenancy: la
 * persona es global, pero su presencia en una organización tiene alta, baja y
 * origen. Que una persona esté vinculada aquí NO le da a esa organización
 * acceso a lo que la persona generó en otra: eso lo gobiernan los
 * consentimientos de compartición (M4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacion_personas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            // Matrícula del alumno o número de empleado, según el vocabulario
            // del tipo de organización.
            $tabla->string('matricula_o_num_empleado', 60)->nullable();

            $tabla->enum('estado', ['activa', 'baja'])->default('activa');

            /*
             * De dónde salió el vínculo:
             * - creada:    el tenant capturó a la persona por primera vez.
             * - vinculada: la persona ya existía y el tenant la ligó.
             * - reclamada: la propia persona reclamó su expediente.
             * Se distinguen porque la responsabilidad legal del alta es
             * distinta en cada caso (Doc 06 §3).
             */
            $tabla->enum('origen_alta', ['creada', 'vinculada', 'reclamada']);

            $tabla->date('fecha_alta');
            $tabla->date('fecha_baja')->nullable();
            $tabla->sellosDeTiempo();

            // Una persona se vincula UNA vez a cada organización; volver
            // significa reactivar el vínculo, no crear otro.
            $tabla->unique(['organizacion_id', 'persona_id']);
            $tabla->index(['organizacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_personas');
    }
};
