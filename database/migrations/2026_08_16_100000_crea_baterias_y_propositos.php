<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M6 — baterías y propósitos.
 *
 * Una BATERÍA es un conjunto de instrumentos que se aplican juntos
 * ("Selección mando medio" = Terman + Cleaver + Zavic + Moss). Un PROPÓSITO es
 * la plantilla de asignación: para qué se aplica, con qué vigencia, en qué
 * modo y bajo qué tipo de consentimiento.
 *
 * El propósito importa más de lo que parece: es lo que la LFPDPPP entiende por
 * FINALIDAD (Doc 06 §3). Un consentimiento firmado para un tamizaje escolar no
 * ampara un proceso de selección laboral, y `asignaciones.proposito_id` es lo
 * que permite comprobarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baterias', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = plantilla del sistema, disponible para todos los tenants.
            $tabla->organizacion(nullable: true);

            $tabla->string('clave', 60);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();

            /*
             * `fijo` obliga a contestar en el orden declarado; `libre` deja
             * elegir. En baterías largas el orden fijo evita que alguien deje
             * para el final el instrumento que más cansa —y lo conteste peor—.
             */
            $tabla->enum('orden_instrumentos', ['fijo', 'libre'])->default('fijo');

            $tabla->boolean('permite_pausas')->default(true);
            $tabla->unsignedSmallInteger('tiempo_total_min')->nullable();
            $tabla->enum('estado', ['borrador', 'activa', 'archivada'])->default('borrador');
            $tabla->sellosDeTiempo();

            $tabla->unique(['organizacion_id', 'clave'], 'baterias_clave_unica');
        });

        Schema::create('bateria_instrumentos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('bateria_id')->constrained('baterias')->cascadeOnDelete();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->restrictOnDelete();
            $tabla->unsignedSmallInteger('orden')->default(0);

            /*
             * Un instrumento opcional dentro de una batería: si la persona no
             * lo contesta, la batería igual se da por completa. Sirve para el
             * instrumento que sólo aplica a parte de la población.
             */
            $tabla->boolean('obligatorio')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->unique(['bateria_id', 'version_instrumento_id'], 'bateria_instrumento_unico');
        });

        Schema::create('propositos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion(nullable: true);
            $tabla->string('clave', 60);
            $tabla->string('nombre', 160);

            // Uno de los dos, o ninguno si el propósito es genérico y la
            // asignación elige el instrumento.
            $tabla->foreignId('bateria_id')->nullable()
                ->constrained('baterias')->nullOnDelete();
            $tabla->foreignId('version_instrumento_id')->nullable()
                ->constrained('versiones_instrumento')->nullOnDelete();

            // La FINALIDAD que el consentimiento tiene que amparar.
            $tabla->foreignId('tipo_consentimiento_id')
                ->constrained('tipos_consentimiento')->restrictOnDelete();

            $tabla->unsignedSmallInteger('vigencia_dias_default')->default(7);
            $tabla->string('modo_presentacion_default', 20)->default('adulto');
            $tabla->boolean('genera_reporte_integrador')->default(false);
            $tabla->sellosDeTiempo();

            $tabla->unique(['organizacion_id', 'clave'], 'propositos_clave_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('propositos');
        Schema::dropIfExists('bateria_instrumentos');
        Schema::dropIfExists('baterias');
    }
};
