<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M1 — unidades (jerarquía interna del tenant).
 *
 * Plantel, sede, departamento o área. La jerarquía importa para el ALCANCE:
 * un alcance por unidad incluye a sus descendientes (Doc 06 §1, dimensión 2),
 * así que quien coordina una sede alcanza a sus departamentos sin que nadie se
 * los enumere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();

            /*
             * Auto-referencia nullable: una unidad sin padre es raíz. El
             * borrado es restrictivo a propósito —tirar un plantel no debe
             * llevarse en silencio a sus departamentos y a las agrupaciones que
             * cuelgan de ellos—.
             */
            $tabla->foreignId('unidad_padre_id')->nullable()
                ->constrained('unidades')->restrictOnDelete();

            $tabla->string('nombre', 160);
            $tabla->enum('tipo', ['plantel', 'sede', 'departamento', 'area']);
            $tabla->enum('estado', ['activa', 'inactiva'])->default('activa');
            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'unidad_padre_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};
