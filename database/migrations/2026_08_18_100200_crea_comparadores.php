<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 05 §4 — los comparadores.
 *
 * Un puntaje solo no decide nada. Lo que sirve es la comparación: contra el
 * puesto al que aspira, contra sí misma hace un año, contra su grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles_puesto', function (Blueprint $tabla): void {
            $tabla->id();

            // Siempre del tenant: el perfil de "supervisor de piso" de una
            // empresa no es el de otra aunque se llamen igual.
            $tabla->organizacion();

            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();
            $tabla->boolean('activo')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'activo']);
        });

        Schema::create('perfil_puesto_criterios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('perfil_puesto_id')
                ->constrained('perfiles_puesto')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();

            $tabla->enum('tipo_puntaje', [
                'bruto', 'percentil', 'T', 'decatipo', 'ci', 'semaforo',
            ]);

            $tabla->decimal('valor_min', 10, 3)->nullable();
            $tabla->decimal('valor_max', 10, 3)->nullable();

            /*
             * Ponderación: no todos los criterios pesan igual. En un puesto de
             * cajero la escrupulosidad pesa más que la extroversión, y un
             * ajuste que promediara plano diría que los dos candidatos son
             * equivalentes cuando no lo son.
             */
            $tabla->decimal('ponderacion', 6, 3)->default(1);

            $tabla->sellosDeTiempo();

            $tabla->unique(['perfil_puesto_id', 'escala_id'], 'criterio_escala_unico');
        });

        /*
         * Umbral de CAMBIO SIGNIFICATIVO por constructo (Doc 05 §4).
         *
         * Que un percentil suba de 40 a 45 no es noticia: es ruido de medición.
         * Lo que hay que marcarle al profesional es el cambio que sale del
         * error de medida, y cuánto es eso depende del constructo y de la
         * escala en la que se mide. Sin umbral configurable, o se marca todo
         * —y entonces nadie mira las marcas— o no se marca nada.
         */
        Schema::create('umbrales_cambio', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = umbral por omisión de la plataforma; con valor, el que la
            // organización decidió para su población.
            $tabla->organizacion(nullable: true);

            $tabla->string('constructo', 60);

            $tabla->enum('tipo_norma', [
                'percentil', 'T', 'estanina', 'decatipo', 'ci_desviacion', 'semaforo',
            ]);

            $tabla->decimal('delta_minimo', 10, 3);

            $tabla->sellosDeTiempo();

            $tabla->unique(
                ['organizacion_id', 'constructo', 'tipo_norma'],
                'umbral_constructo_unico'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umbrales_cambio');
        Schema::dropIfExists('perfil_puesto_criterios');
        Schema::dropIfExists('perfiles_puesto');
    }
};
