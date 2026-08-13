<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M4 — expediente_campos y expediente_valores.
 *
 * Config-driven (principio P3): el expediente NO se programa. Un campo nuevo es
 * una fila en `expediente_campos`, no una columna ni una migración. Una fila
 * por campo, una fila por valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expediente_campos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('seccion_id')
                ->constrained('secciones_expediente')->cascadeOnDelete();

            $tabla->string('clave', 60);
            $tabla->string('etiqueta', 160);

            $tabla->enum('tipo_dato', [
                'texto', 'numero', 'fecha', 'catalogo', 'booleano', 'archivo',
            ]);

            // Sólo para tipo_dato = catalogo.
            $tabla->foreignId('catalogo_opciones_id')->nullable()
                ->constrained('catalogos_opciones')->nullOnDelete();

            $tabla->boolean('obligatorio')->default(false);

            /*
             * QUIÉN puede llenarlo, que no es lo mismo que quién puede verlo.
             * El titular captura sus datos generales; un antecedente médico
             * relevante lo captura un profesional. Lo capturado por titular o
             * tutor nace `pendiente_validacion`.
             */
            $tabla->enum('quien_puede_llenar', ['titular', 'tutor', 'profesional', 'admin']);

            $tabla->foreignId('nivel_sensibilidad_id')->constrained('niveles_sensibilidad');
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->sellosDeTiempo();

            $tabla->unique(['seccion_id', 'clave']);
        });

        Schema::create('expediente_valores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('expediente_id')
                ->constrained('expedientes')->cascadeOnDelete();
            $tabla->foreignId('campo_id')
                ->constrained('expediente_campos')->restrictOnDelete();

            /*
             * En qué tenant se capturó. NULL = lo capturó la persona desde su
             * portal, fuera de cualquier organización.
             *
             * Sin FK y sin global scope a propósito: el valor pertenece al
             * expediente —que es global— y el contexto es un ATRIBUTO suyo,
             * no su dueño. Acotarlo por tenant escondería del expediente de
             * vida justo lo que otra organización capturó, que es lo que la
             * persona puede decidir compartir.
             */
            $tabla->unsignedBigInteger('organizacion_id_contexto')->nullable();

            // Una columna por tipo, no una de texto para todo: `valor_fecha`
            // como varchar hace imposible ordenar y comparar, y el expediente
            // longitudinal vive de comparar.
            $tabla->text('valor_texto')->nullable();
            $tabla->decimal('valor_numero', 14, 4)->nullable();
            $tabla->date('valor_fecha')->nullable();
            $tabla->foreignId('valor_opcion_id')->nullable()
                ->constrained('opciones_catalogo')->restrictOnDelete();
            $tabla->unsignedBigInteger('media_id')->nullable();

            $tabla->foreignId('capturado_por')->constrained('personas')->restrictOnDelete();

            $tabla->enum('estado', ['pendiente_validacion', 'validado', 'rechazado'])
                ->default('pendiente_validacion');

            $tabla->foreignId('validado_por')->nullable()
                ->constrained('personas')->nullOnDelete();

            /*
             * Histórico por VERSIÓN: corregir un dato no lo pisa, agrega una
             * versión. El vigente es la mayor versión VALIDADA.
             *
             * Es lo que hace posible la rectificación ARCO sin destruir el
             * dato anterior (Doc 06 §3) y lo que deja ver que un dato cambió,
             * cuándo y quién lo validó.
             */
            $tabla->unsignedInteger('version')->default(1);

            $tabla->sellosDeTiempo();

            $tabla->unique(['expediente_id', 'campo_id', 'version'], 'valores_version_unica');
            $tabla->index(['expediente_id', 'campo_id', 'estado'], 'valores_vigente_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expediente_valores');
        Schema::dropIfExists('expediente_campos');
    }
};
