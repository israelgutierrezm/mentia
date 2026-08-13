<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derechos ARCO (Doc 06 §3 — LFPDPPP).
 *
 * Acceso, Rectificación, Cancelación y Oposición. No es un formulario de
 * contacto: la ley fija PLAZOS —20 días hábiles para responder, 15 más para
 * hacerlo efectivo— y exige respuesta documentada. Una solicitud que se
 * traspapela es un incumplimiento con sanción, y sin fecha de recepción
 * registrada no hay forma de demostrar que se contestó a tiempo.
 *
 * Los efectos técnicos de cada derecho están definidos en el Doc 06 §3:
 * acceso → exportación del expediente; rectificación → versionado de valores;
 * cancelación → supresión o bloqueo según obligaciones de conservación,
 * documentando las excepciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_arco', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('uuid')->unique();

            /*
             * La organización RESPONSABLE del tratamiento. El titular ejerce su
             * derecho ante quien trata sus datos, no ante la plataforma: Mentia
             * es el encargado y el tenant el responsable (Doc 06 §3).
             */
            $tabla->organizacion();

            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            /*
             * Quién la presenta: el titular o su tutor acreditado. Se guarda
             * aparte de `persona_id` porque una madre puede ejercer el derecho
             * de su hijo y la constancia tiene que decir quién firmó.
             */
            $tabla->foreignId('presentada_por')->constrained('personas')->restrictOnDelete();

            $tabla->enum('derecho', ['acceso', 'rectificacion', 'cancelacion', 'oposicion']);

            $tabla->text('descripcion');

            $tabla->enum('estado', [
                'recibida', 'en_tramite', 'procedente', 'improcedente', 'cumplida',
            ])->default('recibida');

            $tabla->dateTime('recibida_en', precision: 3);

            /*
             * El plazo se CALCULA al recibir y se guarda. Recalcularlo después
             * con los días hábiles de hoy daría una fecha distinta si cambia el
             * calendario de asuetos, y el plazo que corre es el que corría el
             * día que entró la solicitud.
             */
            $tabla->date('vence_respuesta');
            $tabla->date('vence_cumplimiento')->nullable();

            $tabla->dateTime('respondida_en', precision: 3)->nullable();
            $tabla->foreignId('respondida_por')->nullable()
                ->constrained('personas')->restrictOnDelete();

            $tabla->text('respuesta')->nullable();

            /*
             * Las excepciones a la cancelación se DOCUMENTAN: hay datos que la
             * organización está obligada a conservar (la bitácora, por
             * ejemplo). Decir "no se puede" sin decir por qué es lo que
             * convierte una negativa legítima en una queja ante el INAI.
             */
            $tabla->text('excepciones_aplicadas')->nullable();

            // El expediente exportado, cuando el derecho es de acceso.
            $tabla->unsignedBigInteger('media_id')->nullable();

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'estado', 'vence_respuesta'], 'arco_bandeja_index');
            $tabla->index(['persona_id', 'recibida_en'], 'arco_persona_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_arco');
    }
};
