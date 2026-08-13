<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M7 — aplicaciones y su estado por bloque.
 *
 * Una aplicación es UNA instancia de respuesta de UN instrumento por UNA
 * persona. Una batería de cuatro instrumentos produce cuatro aplicaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('uuid')->unique();
            $tabla->organizacion();

            $tabla->foreignId('asignacion_destinatario_id')
                ->constrained('asignacion_destinatarios')->restrictOnDelete();

            /*
             * Redundante a propósito (Doc 03 §M7): se puede llegar a la versión
             * por la asignación, pero `respuestas` se proyecta en decenas de
             * millones de filas y la reportería no debe tener que atravesar
             * tres tablas para saber qué instrumento se contestó.
             */
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->restrictOnDelete();

            /*
             * NULL si la asignación es ANÓNIMA. Es la columna que hace real el
             * anonimato: no hay vínculo que reconstruir porque no existe
             * (Doc 03 §M6). Irreversible por diseño.
             */
            $tabla->foreignId('persona_id')->nullable()
                ->constrained('personas')->restrictOnDelete();

            // Informante o examinador, cuando no responde la propia persona.
            $tabla->foreignId('quien_respondio_persona_id')->nullable()
                ->constrained('personas')->nullOnDelete();

            $tabla->enum('modalidad', [
                'en_linea', 'presencial_kiosco', 'offline_sync',
                'captura_protocolo', 'captura_fisica',
            ])->default('en_linea');

            $tabla->string('modo_presentacion', 20)->default('adulto');

            $tabla->enum('estado', [
                'iniciada', 'en_pausa', 'completada', 'expirada', 'anulada',
            ])->default('iniciada');

            $tabla->dateTime('iniciada_en', precision: 3);
            $tabla->dateTime('finalizada_en', precision: 3)->nullable();

            /*
             * Tiempo EFECTIVO: no el reloj de pared. Se acumula al pausar, así
             * que una pausa de dos horas no cuenta como tiempo de respuesta —y
             * el tiempo es insumo de la etapa de validez (Doc 05 §2).
             */
            $tabla->unsignedInteger('tiempo_efectivo_seg')->default(0);

            $tabla->string('dispositivo', 255)->nullable();
            $tabla->string('ip', 45)->nullable();

            /*
             * CONGELADA al aplicar. Si se calculara al calificar, alguien que
             * cumple años entre una cosa y otra se normalizaría con la tabla
             * equivocada — y en preescolar seis meses cambian la norma.
             */
            $tabla->unsignedSmallInteger('edad_meses_al_aplicar')->nullable();

            $tabla->enum('validez', ['pendiente', 'valida', 'dudosa', 'invalida'])
                ->default('pendiente');
            $tabla->string('motivo_invalidez', 255)->nullable();

            $tabla->unsignedTinyInteger('numero_intento')->default(1);

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'estado']);
            $tabla->index(['persona_id', 'creado_en'], 'aplicaciones_persona_index');
            $tabla->index(['asignacion_destinatario_id', 'numero_intento'], 'aplicaciones_intento_index');
        });

        Schema::create('aplicacion_bloques', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $tabla->foreignId('bloque_id')->constrained('bloques')->restrictOnDelete();

            /*
             * EL CRONÓMETRO VIVE AQUÍ (Doc 02 §7). El tiempo restante siempre
             * se calcula en el servidor desde `iniciado_en` más la duración del
             * bloque; el cliente sólo lo muestra.
             *
             * Si el cliente llevara la cuenta, cambiar la hora del sistema o
             * abrir la consola bastaría para tener el doble de tiempo en una
             * prueba cronometrada —y el tiempo es parte del constructo que se
             * mide—.
             */
            $tabla->dateTime('iniciado_en', precision: 3)->nullable();
            $tabla->dateTime('finalizado_en', precision: 3)->nullable();
            $tabla->unsignedInteger('tiempo_consumido_seg')->default(0);

            $tabla->enum('estado', ['pendiente', 'en_curso', 'completado', 'expirado'])
                ->default('pendiente');

            $tabla->sellosDeTiempo();

            $tabla->unique(['aplicacion_id', 'bloque_id'], 'aplicacion_bloque_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicacion_bloques');
        Schema::dropIfExists('aplicaciones');
    }
};
