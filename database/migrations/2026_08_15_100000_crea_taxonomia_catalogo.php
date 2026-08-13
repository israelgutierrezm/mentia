<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — la taxonomía del catálogo. Todo GLOBAL.
 *
 * El catálogo no lleva organizacion_id (Doc 02 §3): un instrumento es el mismo
 * para todos los tenants. Lo que sí es de cada organización es su HABILITACIÓN
 * (tenant_instrumentos) y, cuando la licencia lo exige, el contenido que
 * captura por su cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Categorías y subcategorías en UNA tabla auto-referenciada.
         *
         * El Doc 03 las nombra por separado pero les da el mismo esquema con
         * `padre_id nullable`: sin padre es categoría, con padre es
         * subcategoría. Dos tablas idénticas obligarían a duplicar cada
         * consulta y a decidir en cada punto cuál mirar.
         */
        Schema::create('categorias_instrumento', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('padre_id')->nullable()
                ->constrained('categorias_instrumento')->cascadeOnDelete();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->sellosDeTiempo();

            $tabla->index('padre_id');
        });

        /*
         * Los "órganos" del expediente (Doc 01 §1). Es la dimensión por la que
         * el perfil longitudinal compara a lo largo de la vida: cognitivo a
         * los 6 y cognitivo a los 22 son la misma serie.
         */
        Schema::create('dominios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->sellosDeTiempo();
        });

        /*
         * Catálogo EXTENSIBLE: cada clave mapea a un componente de render en
         * el motor de aplicación (Fase 6). Agregar un tipo de reactivo nuevo
         * es una fila más un componente, no tocar el motor.
         */
        Schema::create('tipos_reactivo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->boolean('requiere_opciones')->default(true);
            $tabla->boolean('admite_multimedia')->default(false);
            $tabla->sellosDeTiempo();
        });

        Schema::create('poblaciones_norma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('pais', 60)->nullable();
            $tabla->string('descripcion', 255)->nullable();
            $tabla->string('fuente', 255)->nullable();
            $tabla->sellosDeTiempo();
        });

        // Crosswalk O*NET para el vocacional (Doc 04 §3).
        Schema::create('ocupaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 40)->unique();
            $tabla->string('nombre', 200);
            $tabla->string('codigo_riasec', 6)->nullable();
            $tabla->string('descripcion', 500)->nullable();
            $tabla->sellosDeTiempo();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocupaciones');
        Schema::dropIfExists('poblaciones_norma');
        Schema::dropIfExists('tipos_reactivo');
        Schema::dropIfExists('dominios');
        Schema::dropIfExists('categorias_instrumento');
    }
};
