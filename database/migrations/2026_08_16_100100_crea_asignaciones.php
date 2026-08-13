<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M6 — asignaciones y sus destinatarios.
 *
 * La asignación es la ORDEN: a quién, qué, cuándo y para qué. El destinatario
 * es cada persona concreta con su token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Folio legible, no el id. Es lo que se dicta por teléfono cuando
             * alguien llama a preguntar por su evaluación, y lo que viaja en
             * la API (Doc 07 §4 usa /asignaciones/{folio}).
             */
            $tabla->string('folio', 24)->unique();

            $tabla->organizacion();

            // EXACTAMENTE UNO de los dos. El CHECK va más abajo.
            $tabla->foreignId('version_instrumento_id')->nullable()
                ->constrained('versiones_instrumento')->restrictOnDelete();
            $tabla->foreignId('bateria_id')->nullable()
                ->constrained('baterias')->restrictOnDelete();

            $tabla->foreignId('proposito_id')->constrained('propositos')->restrictOnDelete();

            $tabla->enum('origen_tipo', ['individual', 'agrupacion', 'campania']);
            $tabla->foreignId('agrupacion_id')->nullable()
                ->constrained('agrupaciones')->nullOnDelete();

            /*
             * Agrupación DINÁMICA vs SNAPSHOT.
             *
             * Con `true`, quien se dé de alta en el grupo después de lanzar la
             * asignación también la recibe. Es lo que hace que un alumno que
             * llega en octubre no se quede fuera del tamizaje anual; y lo que
             * NO se quiere en una campaña con fecha de corte, donde el padrón
             * tiene que quedar congelado.
             */
            $tabla->boolean('incluir_nuevos_miembros')->default(false);

            $tabla->foreignId('asignado_por')->constrained('personas')->restrictOnDelete();

            /*
             * DISCRETA: sólo la ve quien la creó y quien tenga nivel de
             * sensibilidad suficiente. Es el caso clínico —una psicóloga
             * asigna un PHQ-9 a un colaborador— donde que el resto del área
             * sepa que existe esa evaluación ya es una filtración.
             */
            $tabla->boolean('es_discreta')->default(false);

            /*
             * ANÓNIMA: la aplicación resultante guarda persona_id NULL.
             * IRREVERSIBLE POR DISEÑO (Doc 03 §M6): NOM-035 y clima laboral
             * sólo funcionan si la gente cree —con razón— que nadie puede
             * reconstruir quién contestó qué.
             */
            $tabla->boolean('es_anonima')->default(false);

            $tabla->dateTime('ventana_inicio');
            $tabla->dateTime('ventana_fin');

            $tabla->unsignedTinyInteger('intentos_permitidos')->default(1);
            $tabla->string('modo_presentacion', 20)->default('adulto');

            $tabla->boolean('requiere_consentimiento')->default(true);
            $tabla->foreignId('tipo_consentimiento_id')->nullable()
                ->constrained('tipos_consentimiento')->nullOnDelete();

            $tabla->enum('estado', ['borrador', 'activa', 'cerrada', 'cancelada'])
                ->default('borrador');

            $tabla->sellosDeTiempo();

            $tabla->index(['organizacion_id', 'estado']);
            $tabla->index(['agrupacion_id', 'incluir_nuevos_miembros'], 'asignaciones_dinamicas_index');
        });

        /*
         * CHECK de instrumento XOR batería (Doc 08, Fase 5).
         *
         * A nivel de BASE y no sólo de servicio: una asignación con los dos o
         * con ninguno no se puede aplicar, y el motor de la Fase 6 tendría que
         * adivinar qué contestar. MySQL 8 hace cumplir los CHECK de verdad
         * desde la 8.0.16.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones
            ADD CONSTRAINT asignaciones_instrumento_xor_bateria
            CHECK (
                (version_instrumento_id IS NOT NULL AND bateria_id IS NULL)
                OR (version_instrumento_id IS NULL AND bateria_id IS NOT NULL)
            )
        SQL);

        // Una ventana que termina antes de empezar deja a todo el mundo fuera
        // sin que nadie entienda por qué.
        DB::statement(<<<'SQL'
            ALTER TABLE asignaciones
            ADD CONSTRAINT asignaciones_ventana_coherente
            CHECK (ventana_fin > ventana_inicio)
        SQL);

        Schema::create('asignacion_destinatarios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('asignacion_id')->constrained('asignaciones')->cascadeOnDelete();
            $tabla->foreignId('persona_id')->constrained('personas')->restrictOnDelete();

            /*
             * Quién responde, si no es la propia persona: el informante. La
             * madre contesta el M-CHAT SOBRE su hijo, así que `persona_id` es
             * el niño y `quien_responde_persona_id` es la madre.
             */
            $tabla->foreignId('quien_responde_persona_id')->nullable()
                ->constrained('personas')->nullOnDelete();

            $tabla->enum('estado', [
                'pendiente', 'consentimiento_pendiente', 'notificada',
                'en_curso', 'completada', 'expirada', 'exenta',
            ])->default('pendiente');

            /*
             * Token de UN SOLO USO, 64 caracteres. Se guarda el HASH, no el
             * token: quien lea la base no debe poder entrar a contestar en
             * nombre de nadie. El token en claro sólo existe el instante en
             * que se manda por correo.
             */
            $tabla->char('token', 64)->nullable()->unique();
            $tabla->dateTime('token_expira_en')->nullable();
            $tabla->dateTime('token_usado_en')->nullable();

            $tabla->string('motivo_exencion', 255)->nullable();
            $tabla->dateTime('notificada_en')->nullable();
            $tabla->unsignedSmallInteger('recordatorios_enviados')->default(0);

            $tabla->sellosDeTiempo();

            $tabla->unique(['asignacion_id', 'persona_id'], 'destinatario_unico');
            $tabla->index(['asignacion_id', 'estado']);
            $tabla->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_destinatarios');
        Schema::dropIfExists('asignaciones');
    }
};
