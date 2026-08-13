<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 05 §3 y Doc 03 §M9 — reactivos centinela y alertas.
 *
 * Los centinelas viven FUERA del pipeline: se evalúan de forma SÍNCRONA al
 * recibir cada lote de respuestas, con la aplicación todavía en curso. Es la
 * diferencia entre enterarse de una ideación suicida ahora o mañana cuando la
 * cola termine de calificar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centinela_condiciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();

            // Dispara con esta opción concreta…
            $tabla->foreignId('opcion_id')->nullable()
                ->constrained('opciones_reactivo')->cascadeOnDelete();

            // …o con cualquier valor que cumpla la comparación.
            $tabla->string('operador', 20)->nullable();
            $tabla->decimal('valor', 10, 3)->nullable();

            $tabla->enum('severidad', ['critica', 'alta', 'media'])->default('critica');

            /*
             * Lo que se le dice a quien atiende. NO lo que se le muestra a la
             * persona evaluada: a ella se le presenta, al terminar, un mensaje
             * cuidado con recursos de apoyo, nunca "diste positivo a X"
             * (Doc 05 §3).
             */
            $tabla->string('mensaje', 255);

            $tabla->sellosDeTiempo();

            $tabla->index('reactivo_id');
        });

        Schema::create('alertas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();

            // NULL en aplicaciones anónimas: hay alerta, pero no hay a quién
            // atribuirla. Es el precio del anonimato y está asumido.
            $tabla->foreignId('persona_id')->nullable()
                ->constrained('personas')->nullOnDelete();

            $tabla->foreignId('aplicacion_id')->nullable()
                ->constrained('aplicaciones')->nullOnDelete();

            $tabla->enum('tipo', ['centinela', 'bandera_resultado', 'protocolo', 'validez']);
            $tabla->enum('severidad', ['critica', 'alta', 'media']);

            $tabla->foreignId('reactivo_id')->nullable()
                ->constrained('reactivos')->nullOnDelete();

            $tabla->string('mensaje', 255);

            $tabla->enum('estado', ['nueva', 'notificada', 'atendida', 'cerrada'])
                ->default('nueva');

            $tabla->foreignId('atendida_por')->nullable()
                ->constrained('personas')->nullOnDelete();
            $tabla->dateTime('atendida_en')->nullable();

            /*
             * Cerrar una alerta EXIGE resolución (Doc 06 §5). Una alerta que se
             * puede cerrar en blanco es una alerta que se cierra para limpiar
             * la bandeja.
             */
            $tabla->text('resolucion')->nullable();

            // Milésimas: una alerta crítica se mide en segundos de respuesta.
            $tabla->dateTime('creada_en', precision: 3);

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'estado', 'severidad'], 'alertas_bandeja_index');
            $tabla->index(['persona_id', 'creada_en'], 'alertas_persona_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('centinela_condiciones');
    }
};
