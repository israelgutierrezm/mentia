<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M4 — expedientes, secciones y catálogos de opciones.
 *
 * El expediente es 1:1 con la persona y GLOBAL, como ella: es el expediente de
 * VIDA, no el de una escuela. Lo que lleva contexto de tenant son los VALORES
 * capturados, no el expediente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedientes', function (Blueprint $tabla): void {
            $tabla->id();

            // Único: una persona, un expediente. Si pudiera haber dos, la línea
            // de vida se partiría y el producto deja de existir.
            $tabla->foreignId('persona_id')->unique()
                ->constrained('personas')->cascadeOnDelete();

            $tabla->enum('estado', ['activo', 'bloqueado'])->default('activo');

            /*
             * Bloqueo por re-consentimiento pendiente (Doc 06 §3): al cumplir
             * 18 años el titular, mientras no re-consienta, TERCEROS no
             * acceden. El titular sí: es su dato.
             */
            $tabla->string('motivo_bloqueo', 160)->nullable();

            $tabla->sellosDeTiempo();
        });

        Schema::create('secciones_expediente', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->foreignId('nivel_sensibilidad_id')->constrained('niveles_sensibilidad');
            $tabla->sellosDeTiempo();
        });

        /*
         * Catálogos de opciones para los campos de tipo `catalogo`.
         *
         * AMBIGÜEDAD DEL DOC 03, resuelta aquí: el diccionario referencia
         * `expediente_campos.catalogo_opciones_id` y
         * `expediente_valores.valor_opcion_id` pero nunca define dónde viven
         * las opciones. Se parten en dos tablas —el catálogo y sus filas—
         * porque el mismo catálogo (entidades federativas, tipo de sangre) lo
         * usan varios campos, y duplicar sus opciones por campo es como
         * terminan divergiendo. Ver docs/decisiones.md.
         */
        Schema::create('catalogos_opciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->sellosDeTiempo();
        });

        Schema::create('opciones_catalogo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('catalogo_opciones_id')
                ->constrained('catalogos_opciones')->cascadeOnDelete();
            $tabla->string('clave', 60);
            $tabla->string('etiqueta', 160);
            $tabla->unsignedSmallInteger('orden')->default(0);

            /*
             * Se APAGA, no se borra. Una opción retirada sigue estando en los
             * valores históricos que la eligieron, y borrarla dejaría esas
             * filas apuntando a nada.
             */
            $tabla->boolean('activo')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->unique(['catalogo_opciones_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_catalogo');
        Schema::dropIfExists('catalogos_opciones');
        Schema::dropIfExists('secciones_expediente');
        Schema::dropIfExists('expedientes');
    }
};
