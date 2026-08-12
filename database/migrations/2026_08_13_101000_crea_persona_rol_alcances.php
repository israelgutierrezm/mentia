<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M3 — persona_rol_alcances: la dimensión que Spatie no cubre.
 *
 * Spatie contesta "¿este rol puede ver resultados?". No contesta "¿de QUIÉN?".
 * Esta tabla es la respuesta: el permiso dice qué, el alcance dice sobre quién.
 *
 * Los dos hacen falta. El rol `docente` tiene `resultados.ver_resumen` en toda
 * la organización si sólo se mira el permiso; con el alcance, lo tiene sobre
 * su grupo y nada más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_rol_alcances', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();

            $tabla->enum('alcance_tipo', ['organizacion', 'unidad', 'agrupacion', 'persona']);

            /*
             * FK LÓGICA: apunta a organizaciones, unidades, agrupaciones o
             * personas según alcance_tipo, así que no puede llevar constraint.
             * Es lo que permite agregar un tipo de alcance nuevo sin migrar.
             * A cambio, lo que apunte a algo borrado deja de conceder acceso en
             * vez de reventar — el servicio resuelve por relación, no por id
             * suelto.
             */
            $tabla->unsignedBigInteger('alcance_id');

            /*
             * El acceso CADUCA. Un orientador con alcance sobre el grupo 3°A
             * lo pierde al cerrar el ciclo, sin que nadie tenga que acordarse
             * de quitárselo. vigencia_fin NULL = sin caducidad.
             */
            $tabla->date('vigencia_inicio');
            $tabla->date('vigencia_fin')->nullable();

            $tabla->foreignId('otorgado_por')->nullable()
                ->constrained('personas')->nullOnDelete();

            $tabla->sellosDeTiempo();

            /*
             * Nombres explícitos: los que genera Laravel por convención pasan
             * de los 64 caracteres que admite MySQL como identificador y la
             * migración revienta al crear el índice, no al declararlo.
             */
            $tabla->index(
                ['persona_id', 'organizacion_id', 'vigencia_fin'],
                'alcances_persona_org_vigencia_index'
            );
            $tabla->index(['alcance_tipo', 'alcance_id'], 'alcances_ambito_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_rol_alcances');
    }
};
