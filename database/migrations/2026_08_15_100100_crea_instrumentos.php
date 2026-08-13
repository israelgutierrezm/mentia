<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — instrumentos y sus versiones.
 *
 * El licenciamiento es ESTRUCTURAL (principio P8): la tabla distingue lo que
 * se puede precargar completo, lo que sólo se precarga como esqueleto y lo que
 * jamás lleva contenido. No es una nota en un campo de texto: decide qué
 * puede hacer el sistema con cada prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrumentos', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * NULL = instrumento GLOBAL del sistema. Con valor = instrumento
             * propio del tenant (Doc 03 §M5, instrumentos_propios). Mismo
             * esquema, misma maquinaria de calificación: una escuela que
             * inventa su encuesta de clima no necesita código nuevo.
             */
            $tabla->organizacion(nullable: true);

            $tabla->string('clave', 60);
            $tabla->string('nombre', 200);
            $tabla->string('nombre_corto', 60)->nullable();

            $tabla->foreignId('subcategoria_id')->nullable()
                ->constrained('categorias_instrumento')->nullOnDelete();
            $tabla->foreignId('dominio_id')->constrained('dominios');

            /*
             * - dominio_publico:            contenido completo precargado.
             * - requiere_licencia_tenant:   esqueleto; el tenant captura sus
             *                               reactivos bajo su propia licencia.
             * - solo_captura:               la editorial prohíbe aplicarlo en
             *                               línea (WISC, ADOS-2, MMPI); sólo se
             *                               capturan resultados.
             */
            $tabla->enum('estatus_licencia', [
                'dominio_publico', 'requiere_licencia_tenant', 'solo_captura',
            ]);

            $tabla->enum('contenido_incluido', ['completo', 'esqueleto', 'ninguno']);

            $tabla->foreignId('nivel_sensibilidad_id')->constrained('niveles_sensibilidad');

            $tabla->enum('modo_calificacion', [
                'algoritmica', 'captura_protocolo', 'interpretacion_experta',
            ]);
            $tabla->enum('quien_responde', [
                'autoaplicada', 'informante', 'examinador', 'mixta',
            ]);

            // Población objetivo en MESES: a los 3 años la diferencia entre 36
            // y 42 meses cambia la norma. En años no se puede expresar.
            $tabla->unsignedInteger('edad_min_meses')->nullable();
            $tabla->unsignedInteger('edad_max_meses')->nullable();

            $tabla->unsignedSmallInteger('duracion_estimada_min')->nullable();
            $tabla->boolean('requiere_supervision')->default(false);

            // Ficha técnica.
            $tabla->string('autor', 200)->nullable();
            $tabla->unsignedSmallInteger('anio')->nullable();
            $tabla->string('poblacion_norma', 200)->nullable();
            $tabla->text('referencia_bibliografica')->nullable();

            $tabla->sellosDeTiempo();

            // Única POR ORGANIZACIÓN: el sistema tiene su `phq9` y una escuela
            // puede tener el suyo propio sin chocar.
            $tabla->unique(['organizacion_id', 'clave']);
            $tabla->index(['dominio_id', 'estatus_licencia']);
        });

        Schema::create('versiones_instrumento', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('instrumento_id')->constrained('instrumentos')->cascadeOnDelete();
            $tabla->string('version', 20);
            $tabla->char('idioma', 5)->default('es-MX');

            /*
             * INMUTABLE TRAS PUBLICARSE (principio P4). Una corrección no edita
             * la versión: publica otra.
             *
             * El bloqueo vive en el servicio y está probado, no en un CHECK:
             * lo que hay que impedir no es cambiar este enum, sino escribir
             * REACTIVOS, CLAVES o BAREMOS de una versión ya publicada. Una
             * aplicación de hace dos años apunta a esta versión exacta, y si
             * su contenido cambiara, su resultado dejaría de ser reproducible.
             */
            $tabla->enum('estado', ['borrador', 'publicada', 'retirada'])->default('borrador');

            $tabla->dateTime('publicada_en')->nullable();
            $tabla->text('notas_version')->nullable();
            $tabla->sellosDeTiempo();

            $tabla->unique(['instrumento_id', 'version', 'idioma'], 'versiones_unica');
            $tabla->index(['instrumento_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_instrumento');
        Schema::dropIfExists('instrumentos');
    }
};
