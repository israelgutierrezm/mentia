<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de recalificaciones.
 *
 * DESVIACIÓN DOCUMENTADA del Doc 03: el diccionario no trae estas tablas, y el
 * Doc 08 exige para la Fase 7 un comando de recalificación "conservando
 * histórico". Sin un lugar donde guardar lo anterior, recalificar PISA el
 * resultado que se le entregó a alguien —posiblemente el que sustentó una
 * contratación o una canalización— y una impugnación de hace seis meses deja de
 * poder reconstruirse. El Doc 05 §1.2 pide trazabilidad; esto es lo que la hace
 * cierta cuando el catálogo cambia.
 *
 * El archivo se ESCRIBE UNA VEZ y no se toca, igual que la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_archivados', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();

            $tabla->string('motivo', 160);

            // El veredicto de validez que tenía entonces: parte de lo que
            // explica por qué el resultado era el que era.
            $tabla->string('validez', 20);
            $tabla->string('motivo_invalidez', 255)->nullable();

            $tabla->unsignedInteger('version_archivo')->default(1);
            $tabla->dateTime('archivado_en', precision: 3);

            $tabla->index(['aplicacion_id', 'archivado_en'], 'archivo_aplicacion_index');
        });

        Schema::create('resultado_archivado_escala', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('resultado_archivado_id')
                ->constrained('resultados_archivados')->cascadeOnDelete();

            /*
             * Sin FK a `escalas`: una recalificación puede venir justamente de
             * haber publicado una versión nueva del instrumento, y la escala
             * vieja puede desaparecer. El archivo tiene que sobrevivir a eso, y
             * por eso guarda también la clave en texto.
             */
            $tabla->unsignedBigInteger('escala_id');
            $tabla->string('escala_clave', 40);

            $tabla->decimal('puntaje_bruto', 10, 3);
            $tabla->unsignedBigInteger('baremo_id')->nullable();
            $tabla->decimal('valor_normalizado', 10, 3)->nullable();
            $tabla->string('tipo_norma', 20)->nullable();
            $tabla->string('etiqueta_norma', 40)->nullable();
            $tabla->boolean('sin_norma')->default(false);

            $tabla->sellosDeTiempo();

            $tabla->index('resultado_archivado_id', 'archivo_escala_index');
        });

        Schema::create('resultado_archivado_interpretacion', function (Blueprint $tabla): void {
            $tabla->id();

            // Nombre explícito: el que Laravel genera pasa de los 64 caracteres
            // que MySQL admite para un identificador.
            $tabla->foreignId('resultado_archivado_id')
                ->constrained(
                    table: 'resultados_archivados',
                    indexName: 'archivo_interp_archivo_fk'
                )->cascadeOnDelete();

            $tabla->string('audiencia', 20);
            $tabla->mediumText('texto_resuelto');
            $tabla->string('bandera', 20)->nullable();
            $tabla->unsignedSmallInteger('orden')->default(0);

            $tabla->sellosDeTiempo();

            $tabla->index('resultado_archivado_id', 'archivo_interpretacion_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_archivado_interpretacion');
        Schema::dropIfExists('resultado_archivado_escala');
        Schema::dropIfExists('resultados_archivados');
    }
};
