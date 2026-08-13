<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 05 §1.3 — el pipeline de cada instrumento, descrito como datos.
 *
 * Un instrumento no declara "soy un PHQ-9 y me califico así": declara qué
 * etapas corre y con qué estrategia cada una. Un PHQ-9 suma; un Cleaver hace
 * conteo ipsativo y luego fórmulas derivadas; un M-CHAT pasa por un algoritmo
 * de dos etapas. Los tres recorren el MISMO pipeline y sólo cambian sus filas.
 *
 * Sin esto, cada instrumento nuevo sería una rama en el motor, y el motor
 * acabaría siendo un `switch` de doscientos casos que nadie se atreve a tocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrumento_pipeline', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();

            $tabla->enum('etapa', [
                'validez', 'brutos', 'algoritmos', 'normalizacion',
                'interpretacion', 'banderas',
            ]);

            /*
             * La clave de la estrategia, no una clase PHP: `suma_ponderada`,
             * `mchat_dos_etapas`. El registro la resuelve a su implementación.
             * Guardar el FQCN ataría los datos al espacio de nombres, y
             * renombrar una clase invalidaría las filas de producción.
             */
            $tabla->string('estrategia_clave', 60);

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activa')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->index(['version_instrumento_id', 'etapa', 'orden'], 'pipeline_orden_index');
        });

        /*
         * Los parámetros van en TABLA HIJA, no en una columna JSON (Doc 05 §1.3
         * y principio P3). Que el umbral de omisiones sea 20% tiene que poder
         * consultarse, indexarse y cambiarse con un UPDATE — no editarse dentro
         * de un blob que ningún reporte puede leer.
         */
        Schema::create('instrumento_pipeline_parametros', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('instrumento_pipeline_id')
                ->constrained('instrumento_pipeline')->cascadeOnDelete();

            $tabla->string('clave', 60);
            $tabla->string('valor', 255);

            $tabla->sellosDeTiempo();

            $tabla->unique(['instrumento_pipeline_id', 'clave'], 'pipeline_parametro_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrumento_pipeline_parametros');
        Schema::dropIfExists('instrumento_pipeline');
    }
};
