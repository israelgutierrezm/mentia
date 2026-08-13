<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M9 y Doc 05 §2 etapa 6 — a quién se avisa y qué pasa después.
 *
 * Una alerta que no llega a nadie no sirve de nada. Estas dos tablas son las
 * que convierten "el sistema detectó algo" en "una persona concreta se enteró y
 * tiene que responder".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerta_destinatarios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();

            /*
             * Se configura por TIPO y SEVERIDAD, no por persona. Quien atiende
             * las alertas críticas es un ROL —la psicóloga de guardia—, y las
             * personas entran y salen de ese rol sin que nadie tenga que
             * acordarse de actualizar una lista de correos. Una lista de
             * personas se queda apuntando a quien renunció hace dos años.
             */
            $tabla->enum('tipo', ['centinela', 'bandera_resultado', 'protocolo', 'validez']);
            $tabla->enum('severidad', ['critica', 'alta', 'media']);

            $tabla->unsignedBigInteger('rol_id');

            $tabla->enum('canal', ['app', 'correo', 'sms'])->default('app');
            $tabla->boolean('activo')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->unique(
                ['organizacion_id', 'tipo', 'severidad', 'rol_id', 'canal'],
                'destinatario_unico'
            );
            $tabla->index(['organizacion_id', 'tipo', 'severidad'], 'destinatario_busqueda');
        });

        /*
         * Doc 03 §M6 — escalonamiento automático.
         *
         * Un M-CHAT de riesgo medio dispara la entrevista de seguimiento; un
         * PHQ-9 alto notifica al psicólogo. Es config-driven por la misma razón
         * que todo lo demás: cada tenant tiene su protocolo, y programarlos
         * sería un `if` por organización.
         *
         * TODA acción automática notifica y deja bitácora. El sistema no actúa
         * en silencio sobre el expediente de nadie (Doc 05 §2).
         */
        Schema::create('protocolo_reglas', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = regla de la plataforma, aplicable a todos los tenants.
            $tabla->organizacion(nullable: true);

            $tabla->foreignId('si_version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();

            $tabla->foreignId('condicion_escala_id')
                ->constrained('escalas')->cascadeOnDelete();

            $tabla->enum('tipo_puntaje', [
                'bruto', 'percentil', 'T', 'decatipo', 'ci', 'semaforo',
            ]);

            $tabla->string('operador', 20);

            /*
             * `valor` es texto y no decimal: una condición sobre un semáforo
             * compara contra 'riesgo_medio', no contra un número. Guardarlo como
             * decimal obligaría a una segunda columna y a recordar cuál mirar.
             */
            $tabla->string('valor', 60);

            $tabla->enum('entonces_accion', [
                'asignar_instrumento', 'asignar_bateria', 'notificar_rol', 'marcar_seguimiento',
            ]);

            // A qué apunta la acción: una versión de instrumento, una batería.
            // FK lógica porque depende de la acción.
            $tabla->unsignedBigInteger('entonces_ref_id')->nullable();

            $tabla->unsignedBigInteger('notificar_rol_id')->nullable();

            $tabla->string('nota', 255)->nullable();
            $tabla->boolean('activo')->default(true);

            $tabla->sellosDeTiempo();

            $tabla->index(
                ['si_version_instrumento_id', 'activo'],
                'protocolo_version_index'
            );
        });

        /*
         * Lo que una regla de protocolo YA hizo sobre una aplicación.
         *
         * Sin esto, recalificar una aplicación volvería a disparar la entrevista
         * de seguimiento y a mandar el aviso: la familia recibiría dos veces la
         * misma liga y el psicólogo dos veces la misma alarma, y a la tercera
         * deja de mirarlas.
         */
        Schema::create('protocolo_ejecuciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('protocolo_regla_id')
                ->constrained('protocolo_reglas')->cascadeOnDelete();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();

            $tabla->string('resultado', 160);
            $tabla->dateTime('ejecutada_en', precision: 3);

            $tabla->sellosDeTiempo();

            $tabla->unique(['protocolo_regla_id', 'aplicacion_id'], 'protocolo_una_vez');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocolo_ejecuciones');
        Schema::dropIfExists('protocolo_reglas');
        Schema::dropIfExists('alerta_destinatarios');
    }
};
