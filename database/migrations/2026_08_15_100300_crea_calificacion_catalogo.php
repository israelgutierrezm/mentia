<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — cómo puntúa un instrumento, descrito como datos.
 *
 * Ningún instrumento se programa (principio P3). Que el reactivo 3 sume 2
 * puntos a la escala D es una FILA, no un `if`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claves_calificacion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();

            // NULL cuando la clave aplica al reactivo completo (likert que
            // suma su valor), no a una opción concreta.
            $tabla->foreignId('opcion_id')->nullable()
                ->constrained('opciones_reactivo')->cascadeOnDelete();

            $tabla->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            $tabla->decimal('peso', 6, 3)->default(1);

            /*
             * `rol` es lo que hace posibles los IPSATIVOS. En un Cleaver, la
             * MISMA opción de un cuadro puntúa a una escala como "más" y a
             * otra como "menos": sin esta columna habría que inventar una
             * tabla aparte para elección forzada, y el motor tendría dos
             * caminos en vez de uno.
             */
            $tabla->enum('rol', ['normal', 'mas', 'menos'])->default('normal');

            $tabla->sellosDeTiempo();

            $tabla->index(['version_instrumento_id', 'escala_id'], 'claves_escala_index');
            $tabla->index('reactivo_id');
        });

        Schema::create('reglas_salto', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('reactivo_origen_id')->constrained('reactivos')->cascadeOnDelete();
            $tabla->foreignId('opcion_id')->nullable()
                ->constrained('opciones_reactivo')->cascadeOnDelete();

            $tabla->enum('condicion', ['respondida', 'igual', 'mayor', 'menor']);
            $tabla->string('valor', 40)->nullable();

            $tabla->enum('destino_tipo', ['reactivo', 'bloque', 'fin']);

            // FK lógica: apunta a `reactivos` o a `bloques` según destino_tipo,
            // así que no puede llevar constraint. Con destino_tipo = fin va
            // en null.
            $tabla->unsignedBigInteger('destino_id')->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index('reactivo_origen_id');
        });

        Schema::create('formulas_derivadas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();
            $tabla->foreignId('escala_destino_id')->constrained('escalas')->cascadeOnDelete();

            /*
             * Expresión sobre CLAVES de escala, no sobre ids: `(M - L)` se
             * lee y se corrige; `(e_17 - e_18)` no. La notación se valida al
             * publicar, no se evalúa con eval() —una expresión de catálogo
             * ejecutándose como PHP sería ejecución remota de código—.
             */
            $tabla->string('expresion', 255);

            // Los índices compuestos se calculan en cadena: T = M − L exige
            // que M y L ya estén.
            $tabla->unsignedSmallInteger('orden_evaluacion')->default(0);

            $tabla->sellosDeTiempo();

            $tabla->index(['version_instrumento_id', 'orden_evaluacion'], 'formulas_orden_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulas_derivadas');
        Schema::dropIfExists('reglas_salto');
        Schema::dropIfExists('claves_calificacion');
    }
};
