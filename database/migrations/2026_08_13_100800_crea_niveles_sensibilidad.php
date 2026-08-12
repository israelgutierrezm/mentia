<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M3 — niveles_sensibilidad (global, seed) y rol_sensibilidad_max.
 *
 * La tercera dimensión de la autorización (Doc 06 §1). El permiso dice QUÉ
 * puede hacer un rol; la sensibilidad dice HASTA DÓNDE. Un reclutador con
 * `resultados.ver_detalle` ve el detalle de una prueba laboral y no ve el de
 * un PHQ-9, aunque el permiso sea el mismo — y eso no es una preferencia de
 * producto: es no discriminación laboral (Doc 06 §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niveles_sensibilidad', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->unsignedTinyInteger('nivel')->unique();
            $tabla->string('clave', 40)->unique();
            $tabla->string('nombre', 80);
            $tabla->string('descripcion', 255);
            $tabla->sellosDeTiempo();
        });

        Schema::create('rol_sensibilidad_max', function (Blueprint $tabla): void {
            /*
             * Cuelga del rol de Spatie, que en modo teams ya es de una
             * organización. El tope es del ROL y no de la persona: si se
             * pudiera subir por persona, el rol dejaría de significar algo y
             * la matriz de sensibilidad × alcance no se podría auditar.
             */
            $tabla->foreignId('rol_id')->primary()
                ->constrained('roles')->cascadeOnDelete();

            $tabla->unsignedTinyInteger('nivel_sensibilidad_max');
            $tabla->sellosDeTiempo();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_sensibilidad_max');
        Schema::dropIfExists('niveles_sensibilidad');
    }
};
