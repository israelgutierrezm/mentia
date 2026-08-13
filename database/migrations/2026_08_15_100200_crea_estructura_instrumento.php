<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — escalas, bloques, reactivos y opciones.
 *
 * Una fila por reactivo, una fila por opción (principio P2). Cero JSON: es lo
 * que permite que una clave de calificación apunte a una opción concreta y que
 * `respuestas` guarde a qué opción respondió alguien hace tres años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->string('clave', 40);
            $tabla->string('nombre', 160);

            // Factores de segundo orden: una escala que se compone de otras.
            $tabla->foreignId('escala_padre_id')->nullable()
                ->constrained('escalas')->nullOnDelete();

            /*
             * Escala de validez (deseabilidad social, infrecuencia,
             * inconsistencia). Se marca aquí porque la etapa 1 del pipeline
             * las lee ANTES de calcular nada: si la aplicación es inválida, el
             * resto de los puntajes no significan nada.
             */
            $tabla->boolean('es_validez')->default(false);

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->sellosDeTiempo();

            $tabla->unique(['version_instrumento_id', 'clave'], 'escalas_clave_unica');
        });

        Schema::create('bloques', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->string('clave', 40);
            $tabla->string('titulo', 200);
            $tabla->mediumText('instrucciones')->nullable();
            $tabla->unsignedSmallInteger('orden')->default(0);

            // El cronómetro es del BLOQUE y se calcula server-side desde
            // `iniciado_en` (Doc 02 §7). El cliente sólo lo muestra.
            $tabla->unsignedInteger('tiempo_limite_seg')->nullable();

            $tabla->enum('orden_reactivos', ['fijo', 'aleatorio'])->default('fijo');

            // Bloque de práctica: no puntúa, y el motor exige comprensión
            // antes de dejar pasar al bloque real.
            $tabla->boolean('es_practica')->default(false);

            $tabla->sellosDeTiempo();

            $tabla->unique(['version_instrumento_id', 'clave'], 'bloques_clave_unica');
        });

        Schema::create('reactivos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('bloque_id')->constrained('bloques')->cascadeOnDelete();
            $tabla->foreignId('tipo_reactivo_id')->constrained('tipos_reactivo');

            $tabla->string('codigo', 20);
            $tabla->text('enunciado');
            $tabla->unsignedBigInteger('media_id')->nullable();

            /*
             * NULL = contenido GLOBAL, visible para todos los tenants.
             * Con valor = contenido PRIVADO de esa organización, capturado
             * bajo su propia licencia y JAMÁS visible para otra (Doc 03 §M5).
             *
             * Es el mecanismo que hace posible precargar el esqueleto de un
             * instrumento con copyright —escalas, baremos, interpretaciones—
             * y que cada tenant con licencia ponga los reactivos. Sin FK a
             * organizaciones porque la fila es del catálogo, que es global.
             */
            $tabla->unsignedBigInteger('organizacion_id_contenido')->nullable();

            // Se puntúa al revés (max+min−valor) en la etapa 2.
            $tabla->boolean('es_inverso')->default(false);

            /*
             * Reactivo CENTINELA: su respuesta se evalúa de forma SÍNCRONA al
             * recibirla, con la aplicación todavía en curso (Doc 02 §2). Es el
             * ítem 9 del PHQ-9 y el screener C-SSRS.
             */
            $tabla->boolean('es_centinela')->default(false);

            $tabla->boolean('obligatorio')->default(true);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->unsignedInteger('tiempo_limite_seg')->nullable();
            $tabla->sellosDeTiempo();

            /*
             * El código es único DENTRO de la versión y del ámbito de
             * contenido: el reactivo `01` global y el `01` que capturó una
             * escuela para el mismo esqueleto conviven sin chocar.
             */
            $tabla->unique(
                ['version_instrumento_id', 'organizacion_id_contenido', 'codigo'],
                'reactivos_codigo_unico'
            );
            $tabla->index(['bloque_id', 'orden']);
            $tabla->index(['version_instrumento_id', 'es_centinela'], 'reactivos_centinela_index');
        });

        Schema::create('opciones_reactivo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();
            $tabla->string('codigo', 20);
            $tabla->text('texto');
            $tabla->unsignedBigInteger('media_id')->nullable();

            // Mismo ámbito de contenido que su reactivo.
            $tabla->unsignedBigInteger('organizacion_id_contenido')->nullable();

            // Nullable: en un likert de acuerdo no hay opción "correcta", y
            // false significaría que todas están mal.
            $tabla->boolean('es_correcta')->nullable();

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->sellosDeTiempo();

            $tabla->unique(['reactivo_id', 'codigo'], 'opciones_codigo_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_reactivo');
        Schema::dropIfExists('reactivos');
        Schema::dropIfExists('bloques');
        Schema::dropIfExists('escalas');
    }
};
