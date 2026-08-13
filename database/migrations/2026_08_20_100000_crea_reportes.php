<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M10 — reportes y la capa de IA.
 *
 * Un reporte es un DOCUMENTO ENTREGADO: alguien lo descargó, lo imprimió y lo
 * puso en un expediente físico o se lo dio a una familia. Por eso se guarda el
 * PDF y no se regenera al vuelo: si el catálogo cambia mañana, el papel que
 * alguien tiene en la mano tiene que seguir explicándose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_reporte', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = plantilla del sistema. Con valor, la que el tenant adaptó.
            $tabla->organizacion(nullable: true);

            $tabla->enum('tipo', [
                'individual', 'integrador', 'grupal', 'longitudinal', 'nom035',
            ]);

            /*
             * La audiencia es parte de la IDENTIDAD de la plantilla, no un
             * filtro: el reporte para el profesional y el del evaluado no son
             * el mismo documento con distinto formato, son documentos
             * distintos.
             */
            $tabla->enum('audiencia', [
                'profesional', 'evaluado_adulto', 'tutor', 'infantil',
            ]);

            $tabla->foreignId('version_instrumento_id')->nullable()
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('bateria_id')->nullable()
                ->constrained('baterias')->cascadeOnDelete();

            /*
             * HTML con marcadores, no una plantilla Blade. Una plantilla que un
             * tenant puede editar y que el servidor COMPILA es ejecución de
             * código arbitrario: el render sustituye marcadores y nada más.
             */
            $tabla->mediumText('estructura_html');

            $tabla->boolean('vigente')->default(true);
            $tabla->sellosDeTiempo();

            $tabla->index(
                ['organizacion_id', 'tipo', 'audiencia', 'vigente'],
                'plantillas_resolucion_index'
            );
        });

        Schema::create('reportes_generados', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('uuid')->unique();
            $tabla->organizacion();

            $tabla->enum('tipo', [
                'individual', 'integrador', 'grupal', 'longitudinal', 'nom035',
            ]);
            $tabla->enum('audiencia', [
                'profesional', 'evaluado_adulto', 'tutor', 'infantil',
            ]);

            $tabla->foreignId('persona_id')->nullable()
                ->constrained('personas')->restrictOnDelete();
            $tabla->foreignId('asignacion_id')->nullable()
                ->constrained('asignaciones')->restrictOnDelete();
            $tabla->foreignId('aplicacion_id')->nullable()
                ->constrained('aplicaciones')->restrictOnDelete();

            $tabla->foreignId('plantilla_id')->nullable()
                ->constrained('plantillas_reporte')->nullOnDelete();

            // El PDF, en medialibrary. Nullable mientras se genera.
            $tabla->unsignedBigInteger('media_id')->nullable();

            $tabla->foreignId('generado_por')->constrained('personas')->restrictOnDelete();
            $tabla->dateTime('generado_en', precision: 3);

            /*
             * FIRMA PROFESIONAL. Un reporte psicométrico sin firma es un
             * borrador: el diagnóstico y la responsabilidad son actos de una
             * persona con cédula, no del sistema (principio P6). Los reportes
             * con IA NO se pueden liberar sin esto.
             */
            $tabla->foreignId('firmado_por')->nullable()
                ->constrained('personas')->restrictOnDelete();
            $tabla->dateTime('firmado_en', precision: 3)->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'tipo', 'generado_en'], 'reportes_bandeja_index');
            $tabla->index(['persona_id', 'generado_en'], 'reportes_persona_index');
        });

        Schema::create('reportes_ia', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('reporte_generado_id')
                ->constrained('reportes_generados')->cascadeOnDelete();

            $tabla->string('modelo', 60);

            /*
             * El HASH del insumo, no el insumo. Sirve para probar que dos
             * borradores salieron de los mismos datos —y para detectar que
             * alguien recalificó en medio— sin guardar una segunda copia del
             * material clínico fuera de su tabla.
             */
            $tabla->char('insumo_hash', 64);

            $tabla->mediumText('borrador');

            $tabla->enum('estado', ['borrador', 'validado', 'rechazado'])->default('borrador');

            $tabla->foreignId('validado_por')->nullable()
                ->constrained('personas')->restrictOnDelete();
            $tabla->dateTime('validado_en', precision: 3)->nullable();
            $tabla->text('observaciones_validacion')->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['estado', 'creado_en'], 'reportes_ia_estado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_ia');
        Schema::dropIfExists('reportes_generados');
        Schema::dropIfExists('plantillas_reporte');
    }
};
