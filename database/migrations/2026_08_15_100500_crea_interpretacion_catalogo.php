<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — reglas de interpretación y perfiles tipo.
 *
 * Cada regla emite su texto POR AUDIENCIA: el mismo resultado se le dice
 * distinto a la psicóloga, al adulto evaluado, a la madre de un menor y a un
 * niño (Doc 06 §1). La audiencia se deriva del rol de quien mira, JAMÁS se
 * elige por parámetro del cliente.
 *
 * Y todos los textos se redactan como sugerencia, nunca como diagnóstico
 * (principio P6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_interpretacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();

            // NULL para reglas de combinación o perfil tipo, que no cuelgan de
            // una sola escala.
            $tabla->foreignId('escala_id')->nullable()
                ->constrained('escalas')->cascadeOnDelete();

            $tabla->enum('tipo_regla', ['rango_escala', 'combinacion', 'perfil_tipo']);
            $tabla->enum('tipo_puntaje', [
                'bruto', 'percentil', 'T', 'decatipo', 'ci', 'semaforo',
            ]);

            $tabla->string('operador', 20)->nullable();
            $tabla->decimal('valor_min', 10, 3)->nullable();
            $tabla->decimal('valor_max', 10, 3)->nullable();

            $tabla->enum('audiencia', [
                'profesional', 'evaluado_adulto', 'tutor', 'infantil',
            ]);

            $tabla->mediumText('texto_interpretacion');
            $tabla->mediumText('recomendaciones')->nullable();

            $tabla->enum('bandera', ['verde', 'amarillo', 'rojo'])->nullable();

            // Se resuelven en orden: la primera que encaja gana su hueco.
            $tabla->unsignedSmallInteger('prioridad')->default(0);
            $tabla->boolean('vigente')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->index(
                ['version_instrumento_id', 'audiencia', 'vigente', 'prioridad'],
                'reglas_resolucion_index'
            );
        });

        Schema::create('reglas_interpretacion_condiciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('regla_id')
                ->constrained('reglas_interpretacion')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $tabla->enum('tipo_puntaje', [
                'bruto', 'percentil', 'T', 'decatipo', 'ci', 'semaforo',
            ]);
            $tabla->string('operador', 20);
            $tabla->decimal('valor_min', 10, 3)->nullable();
            $tabla->decimal('valor_max', 10, 3)->nullable();

            // UNA FILA POR CONDICIÓN (principio P2). Una combinación
            // multi-escala guardada como cadena "D>60 AND C>60" no se puede
            // consultar ni validar.
            $tabla->enum('conector', ['AND', 'OR'])->default('AND');

            $tabla->sellosDeTiempo();

            $tabla->index('regla_id');
        });

        Schema::create('perfiles_tipo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();

            // 'D_alto_C_alto', 'RIA' (código RIASEC de tres letras)…
            $tabla->string('codigo', 40);
            $tabla->string('nombre', 160);

            $tabla->mediumText('descripcion_profesional')->nullable();
            $tabla->mediumText('descripcion_evaluado')->nullable();
            $tabla->mediumText('fortalezas')->nullable();
            $tabla->mediumText('areas_desarrollo')->nullable();

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->sellosDeTiempo();

            $tabla->unique(['version_instrumento_id', 'codigo'], 'perfiles_codigo_unico');
        });

        Schema::create('perfil_tipo_condiciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('perfil_tipo_id')->constrained('perfiles_tipo')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $tabla->enum('tipo_puntaje', [
                'bruto', 'percentil', 'T', 'decatipo', 'ci', 'semaforo',
            ]);
            $tabla->string('operador', 20);
            $tabla->decimal('valor_min', 10, 3)->nullable();
            $tabla->decimal('valor_max', 10, 3)->nullable();
            $tabla->enum('conector', ['AND', 'OR'])->default('AND');
            $tabla->sellosDeTiempo();

            $tabla->index('perfil_tipo_id');
        });

        Schema::create('perfil_tipo_ocupaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('perfil_tipo_id')->constrained('perfiles_tipo')->cascadeOnDelete();
            $tabla->foreignId('ocupacion_id')->constrained('ocupaciones')->cascadeOnDelete();
            $tabla->sellosDeTiempo();

            $tabla->unique(['perfil_tipo_id', 'ocupacion_id'], 'perfil_ocupacion_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_tipo_ocupaciones');
        Schema::dropIfExists('perfil_tipo_condiciones');
        Schema::dropIfExists('perfiles_tipo');
        Schema::dropIfExists('reglas_interpretacion_condiciones');
        Schema::dropIfExists('reglas_interpretacion');
    }
};
