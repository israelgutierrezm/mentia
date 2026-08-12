<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M2 — users: autenticación separada de identidad.
 *
 * `users` es la CUENTA; `personas` es la IDENTIDAD. Se separan porque la
 * mayoría de las personas del sistema nunca tendrán cuenta —un niño de
 * preescolar tamizado por M-CHAT existe en el expediente y no inicia sesión— y
 * porque los roles cuelgan de la persona, no de la cuenta.
 *
 * DESVIACIÓN DOCUMENTADA del Doc 03: el diccionario declara la relación en las
 * dos direcciones (`personas.usuario_id` y `users.persona_id`). Se implementa
 * SOLO ésta. Un 1:1 guardado dos veces son dos columnas que pueden decir cosas
 * distintas sin que la base lo impida; con `persona_id` NOT NULL y único, la
 * relación queda completa y `Persona::usuario()` la lee al revés.
 * Ver docs/decisiones.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->foreignId('persona_id')->after('id')
                ->constrained('personas')->restrictOnDelete();

            // Una cuenta por persona. Sin el único, dos altas simultáneas
            // producen dos cuentas para el mismo expediente.
            $tabla->unique('persona_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropForeign(['persona_id']);
            $tabla->dropUnique(['persona_id']);
            $tabla->dropColumn('persona_id');
        });
    }
};
