<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M8 — la salida del pipeline.
 *
 * Cada etapa PERSISTE lo suyo (Doc 05 §1.2). No es redundancia: ante una
 * impugnación hay que poder reconstruir el camino bruto → normalizado →
 * interpretación con los datos de entonces, y un pipeline que sólo guardara el
 * resultado final obligaría a recalificar con el catálogo de hoy para explicar
 * un puntaje de hace tres años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validez_detalle', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();

            $tabla->enum('verificacion', [
                'omisiones', 'patron_repetido', 'tiempo_atipico',
                'escala_validez', 'cronologia_offline',
            ]);

            $tabla->enum('resultado', ['paso', 'advertencia', 'fallo']);

            /*
             * El detalle es lo que hace defendible la decisión: "18 de 21 sin
             * responder (86%)" se puede discutir con la persona; "inválida" a
             * secas, no.
             */
            $tabla->string('detalle', 255);

            $tabla->sellosDeTiempo();

            $tabla->index(['aplicacion_id', 'resultado'], 'validez_resultado_index');
        });

        Schema::create('resultados_escala', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->restrictOnDelete();

            $tabla->decimal('puntaje_bruto', 10, 3);

            /*
             * El baremo con el que se normalizó, guardado por id. Un baremo se
             * puede sustituir por uno mejor; los resultados viejos tienen que
             * seguir diciendo con cuál se calcularon, o dejan de ser
             * reproducibles.
             */
            $tabla->foreignId('baremo_id')->nullable()
                ->constrained('baremos')->nullOnDelete();

            $tabla->decimal('valor_normalizado', 10, 3)->nullable();

            $tabla->enum('tipo_norma', [
                'percentil', 'T', 'estanina', 'decatipo', 'ci_desviacion', 'semaforo',
            ])->nullable();

            $tabla->string('etiqueta_norma', 40)->nullable();

            /*
             * SIN NORMA: hay bruto pero no hay baremo aplicable para esa edad,
             * sexo o población. Se marca en vez de inventar un percentil, y el
             * Doc 05 §2 lo esconde de las vistas del evaluado: un número sin
             * norma se lee como si significara algo.
             */
            $tabla->boolean('sin_norma')->default(false);

            $tabla->dateTime('calculado_en', precision: 3);
            $tabla->sellosDeTiempo();

            $tabla->unique(['aplicacion_id', 'escala_id'], 'resultado_escala_unico');
        });

        Schema::create('resultados_interpretacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();

            $tabla->foreignId('regla_interpretacion_id')->nullable()
                ->constrained('reglas_interpretacion')->nullOnDelete();
            $tabla->foreignId('perfil_tipo_id')->nullable()
                ->constrained('perfiles_tipo')->nullOnDelete();

            $tabla->enum('audiencia', [
                'profesional', 'evaluado_adulto', 'tutor', 'infantil',
            ]);

            /*
             * El texto RESUELTO, con sus variables ya sustituidas, no la
             * plantilla. La regla puede editarse mañana; lo que se le dijo a
             * esta persona ese día no cambia.
             */
            $tabla->mediumText('texto_resuelto');

            $tabla->enum('bandera', ['verde', 'amarillo', 'rojo'])->nullable();
            $tabla->unsignedSmallInteger('orden')->default(0);

            $tabla->sellosDeTiempo();

            $tabla->index(['aplicacion_id', 'audiencia', 'orden'], 'interpretacion_audiencia_index');
        });

        /*
         * LA TABLA DEL EXPEDIENTE LONGITUDINAL (Doc 03 §M8).
         *
         * Es la que hace real la idea rectora del proyecto: la persona es la
         * entidad permanente y las evaluaciones son eventos en su línea de
         * tiempo. Vive colgada de `persona_id`, no de la aplicación, y por eso
         * sobrevive a que la organización que aplicó la prueba desaparezca.
         */
        Schema::create('resultados_normalizados', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('dominio_id')->constrained('dominios')->restrictOnDelete();

            /*
             * El constructo como CADENA y no como llave foránea: 'ansiedad_rasgo',
             * 'razonamiento', 'D'. Un catálogo cerrado de constructos obligaría a
             * migrar cada vez que un instrumento nuevo mide algo con otro nombre,
             * y lo que se compara en la gráfica es el constructo, no su id.
             */
            $tabla->string('constructo', 60);

            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->restrictOnDelete();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();

            // En qué organización se midió. No filtra la serie —el expediente es
            // de la persona— pero sí explica de dónde viene cada punto.
            $tabla->foreignId('organizacion_id_contexto')->nullable()
                ->constrained('organizaciones')->nullOnDelete();

            $tabla->date('fecha');

            $tabla->enum('tipo_norma', [
                'percentil', 'T', 'estanina', 'decatipo', 'ci_desviacion', 'semaforo',
            ]);

            $tabla->decimal('valor', 10, 3);
            $tabla->enum('bandera', ['verde', 'amarillo', 'rojo'])->nullable();

            $tabla->sellosDeTiempo();

            // El índice del Doc 03: alimenta todas las gráficas evolutivas sin
            // joins pesados.
            $tabla->index(
                ['persona_id', 'dominio_id', 'constructo', 'fecha'],
                'normalizados_serie_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_normalizados');
        Schema::dropIfExists('resultados_interpretacion');
        Schema::dropIfExists('resultados_escala');
        Schema::dropIfExists('validez_detalle');
    }
};
