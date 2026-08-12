<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M2 — tutorias.
 *
 * Quién puede consentir y responder EN NOMBRE de un menor. Es una relación
 * entre dos personas globales, no de tenant: la misma madre acredita tutela en
 * la escuela y en el consultorio.
 *
 * La tutela caduca sola. Al cumplir 18 años el menor, un job la pasa a
 * `extinta_mayoria_edad` y abre el periodo de re-consentimiento del titular
 * (Doc 06 §3, LFPDPPP). Mientras no re-consienta, terceros quedan bloqueados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorias', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('tutor_persona_id')->constrained('personas')->restrictOnDelete();
            $tabla->foreignId('menor_persona_id')->constrained('personas')->restrictOnDelete();

            $tabla->enum('parentesco', ['madre', 'padre', 'tutor_legal', 'otro']);

            /*
             * Evidencia documental (acta, resolución judicial). Va como id
             * suelto y no como FK: `media` es de medialibrary y su ciclo de
             * vida no es el de esta tabla.
             */
            $tabla->unsignedBigInteger('documento_media_id')->nullable();

            $tabla->enum('estado', [
                'pendiente_validacion', 'vigente', 'revocada', 'extinta_mayoria_edad',
            ])->default('pendiente_validacion');

            $tabla->date('vigencia_inicio');
            $tabla->date('vigencia_fin')->nullable();

            // Quién la validó. Una tutoría sin validar NO da acceso: el
            // parentesco declarado por quien se registra no acredita nada.
            $tabla->foreignId('validada_por')->nullable()
                ->constrained('personas')->nullOnDelete();

            $tabla->sellosDeTiempo();

            $tabla->unique(['tutor_persona_id', 'menor_persona_id']);
            $tabla->index(['menor_persona_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorias');
    }
};
