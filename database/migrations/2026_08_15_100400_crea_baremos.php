<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — baremos: bruto → puntuación normalizada.
 *
 * Es lo que hace comparable un resultado de los 6 años con uno de los 22
 * (Doc 01 §1). Sin baremo, un puntaje bruto no significa nada fuera de su
 * propia aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baremos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $tabla->foreignId('poblacion_norma_id')->constrained('poblaciones_norma');

            /*
             * NULL = baremo global publicado. Con valor = baremo PROPIO del
             * tenant, construido con su población.
             *
             * La resolución por prioridad de la etapa 4 (Doc 05 §2) es:
             * agrupación → tenant → nacional → global. Una empresa con diez
             * mil aplicaciones tiene mejor norma para su gente que la tabla
             * publicada en un libro de 1998.
             */
            $tabla->organizacion(nullable: true);

            $tabla->enum('tipo_norma', [
                'percentil', 'T', 'estanina', 'decatipo', 'ci_desviacion', 'semaforo',
            ]);

            $tabla->boolean('vigente')->default(true);
            $tabla->string('fuente', 255)->nullable();
            $tabla->sellosDeTiempo();

            $tabla->index(
                ['version_instrumento_id', 'escala_id', 'organizacion_id', 'vigente'],
                'baremos_resolucion_index'
            );
        });

        Schema::create('baremo_filas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('baremo_id')->constrained('baremos')->cascadeOnDelete();

            $tabla->decimal('bruto_min', 10, 3);
            $tabla->decimal('bruto_max', 10, 3);

            /*
             * Segmentación de la fila. NULL = no segmenta por ese eje.
             *
             * La edad va en MESES y se congela al aplicar: un adolescente que
             * cumple años entre la aplicación y la calificación tiene que
             * normalizarse con la edad que TENÍA, no con la de hoy.
             */
            $tabla->unsignedInteger('edad_min_meses')->nullable();
            $tabla->unsignedInteger('edad_max_meses')->nullable();
            $tabla->enum('sexo', ['M', 'F', 'X'])->nullable();
            $tabla->unsignedBigInteger('escolaridad_id')->nullable();

            $tabla->decimal('valor_normalizado', 10, 3);

            // Para normas de semáforo: 'riesgo alto', 'nulo', etc.
            $tabla->string('etiqueta', 40)->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['baremo_id', 'bruto_min', 'edad_min_meses'], 'baremo_filas_busqueda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baremo_filas');
        Schema::dropIfExists('baremos');
    }
};
