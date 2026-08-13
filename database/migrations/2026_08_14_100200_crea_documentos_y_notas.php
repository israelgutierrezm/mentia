<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M4 — documentos del expediente y notas profesionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->boolean('requiere_validacion')->default(true);
            $tabla->sellosDeTiempo();
        });

        Schema::create('expediente_documentos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes')->cascadeOnDelete();
            $tabla->foreignId('tipo_documento_id')->constrained('tipos_documento');

            /*
             * El archivo vive en medialibrary. Va como id suelto y no como FK:
             * el ciclo de vida de `media` es del paquete, y una FK haría que
             * limpiar medios huérfanos reventara contra esta tabla.
             */
            $tabla->unsignedBigInteger('media_id')->nullable();

            $tabla->unsignedBigInteger('organizacion_id_contexto')->nullable();

            $tabla->foreignId('cargado_por')->constrained('personas')->restrictOnDelete();
            $tabla->enum('estado', ['pendiente_validacion', 'validado', 'rechazado'])
                ->default('pendiente_validacion');
            $tabla->foreignId('validado_por')->nullable()
                ->constrained('personas')->nullOnDelete();

            // Un acta no caduca; una constancia médica sí.
            $tabla->date('vigencia_fin')->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['expediente_id', 'tipo_documento_id']);
        });

        Schema::create('notas_profesionales', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes')->cascadeOnDelete();
            $tabla->organizacion();
            $tabla->foreignId('autor_persona_id')->constrained('personas')->restrictOnDelete();

            /*
             * CIFRADO a nivel aplicación (cast `encrypted`, Doc 06 §4). El
             * contenido de una nota clínica no debe poder leerse desde un
             * volcado de la base ni desde un respaldo.
             *
             * `text` y no `varchar`: el texto cifrado ocupa bastante más que
             * el original.
             */
            $tabla->text('contenido');

            $tabla->foreignId('nivel_sensibilidad_id')->constrained('niveles_sensibilidad');

            /*
             * NUNCA visibles para el titular directamente (Doc 03 §M4). No es
             * opacidad: una nota clínica en crudo, sin la conversación que la
             * acompaña, hace daño. Lo que el titular recibe es la
             * interpretación redactada para su audiencia.
             */
            $tabla->enum('visible_para', ['autor', 'nivel_4'])->default('autor');

            $tabla->sellosDeTiempo();

            $tabla->index(['expediente_id', 'organizacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_profesionales');
        Schema::dropIfExists('expediente_documentos');
        Schema::dropIfExists('tipos_documento');
    }
};
