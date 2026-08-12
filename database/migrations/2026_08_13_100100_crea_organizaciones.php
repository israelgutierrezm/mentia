<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M1 — organizaciones y su configuración.
 *
 * La organización es el tenant: su id es el `organizacion_id` que discrimina
 * todas las tablas de tenant y el `team_foreign_key` de Spatie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('nombre', 160);
            $tabla->foreignId('tipo_organizacion_id')->constrained('tipos_organizacion');
            $tabla->char('rfc', 13)->nullable();
            $tabla->enum('estado', ['activa', 'suspendida'])->default('activa');

            /*
             * Zona de presentación del tenant. El sistema almacena en UTC
             * (config/app.php); esto es lo que decide cómo se le dibuja la hora
             * a esta organización. Tijuana y Mérida están a dos horas.
             */
            $tabla->string('zona_horaria', 64)->default('America/Mexico_City');

            $tabla->sellosDeTiempo();

            $tabla->index('estado');
        });

        /*
         * Configuración como FILAS, no como columna JSON ni como columnas
         * fijas (principio P2). Un parámetro nuevo es un renglón, no una
         * migración; y se puede saber cuándo cambió cada uno.
         */
        Schema::create('organizacion_configuraciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();
            $tabla->string('clave', 80);
            $tabla->text('valor')->nullable();
            $tabla->sellosDeTiempo();

            $tabla->unique(['organizacion_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_configuraciones');
        Schema::dropIfExists('organizaciones');
    }
};
