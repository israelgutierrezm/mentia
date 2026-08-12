<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M1 — tipos_agrupacion y agrupaciones.
 *
 * Una agrupación es el conjunto al que se le lanza una evaluación: el grupo
 * 3°A, la vacante de mando medio, el centro de trabajo de la NOM-035, una
 * cohorte, un taller. Todos son la misma estructura con distinto tipo; por eso
 * el tipo es catálogo y no un enum en duro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_agrupacion', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * NULL = plantilla del sistema, disponible para todos los tenants.
             * Con valor = tipo propio de esa organización. Es lo que permite
             * que una escuela invente "academia" sin migrar nada.
             */
            $tabla->organizacion(nullable: true);

            $tabla->string('clave', 40);
            $tabla->string('nombre', 80);
            $tabla->sellosDeTiempo();

            $tabla->unique(['organizacion_id', 'clave']);
        });

        Schema::create('agrupaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();
            $tabla->foreignId('unidad_id')->nullable()
                ->constrained('unidades')->restrictOnDelete();
            $tabla->foreignId('tipo_agrupacion_id')->constrained('tipos_agrupacion');
            $tabla->string('nombre', 160);
            $tabla->date('periodo_inicio')->nullable();
            $tabla->date('periodo_fin')->nullable();
            $tabla->enum('estado', ['activa', 'cerrada'])->default('activa');
            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'estado']);
        });

        Schema::create('agrupacion_miembros', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('agrupacion_id')->constrained('agrupaciones')->cascadeOnDelete();
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $tabla->enum('rol_en_agrupacion', ['evaluado', 'titular_responsable']);
            $tabla->date('fecha_alta');

            /*
             * fecha_baja NULL = membresía vigente. La vigencia temporal es lo
             * que dibuja la línea de vida institucional de la persona y lo que
             * decide el alcance: un docente que dejó el grupo en julio no debe
             * seguir viendo sus resultados en septiembre.
             */
            $tabla->date('fecha_baja')->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['agrupacion_id', 'persona_id', 'fecha_baja']);
            $tabla->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agrupacion_miembros');
        Schema::dropIfExists('agrupaciones');
        Schema::dropIfExists('tipos_agrupacion');
    }
};
