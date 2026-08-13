<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M4 — el aparato de consentimiento.
 *
 * Los datos psicométricos son datos personales SENSIBLES y la LFPDPPP exige
 * consentimiento expreso y por escrito (Doc 06 §3). Aquí se materializa: qué
 * texto exacto se firmó, quién lo firmó, para qué finalidad, ante qué tenant y
 * hasta cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_consentimiento', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 60)->unique();
            $tabla->string('nombre', 120);
            $tabla->string('descripcion', 255)->nullable();
            $tabla->sellosDeTiempo();
        });

        Schema::create('textos_consentimiento', function (Blueprint $tabla): void {
            $tabla->id();

            // NULL = texto de plataforma, para todos los tenants.
            $tabla->organizacion(nullable: true);

            $tabla->foreignId('tipo_consentimiento_id')->constrained('tipos_consentimiento');
            $tabla->unsignedInteger('version');
            $tabla->string('titulo', 200);
            $tabla->mediumText('cuerpo');

            /*
             * Hash del cuerpo. Es lo que permite demostrar, años después, que
             * el texto que la persona aceptó es exactamente éste y no uno
             * editado: el consentimiento apunta a la VERSIÓN, y el hash
             * comprueba que la versión no se tocó.
             *
             * Publicado un texto, su cuerpo NO se edita (principio P4). Se
             * publica una versión nueva.
             */
            $tabla->char('hash_sha256', 64);

            $tabla->date('vigente_desde');
            $tabla->sellosDeTiempo();

            $tabla->unique(
                ['organizacion_id', 'tipo_consentimiento_id', 'version'],
                'textos_version_unica'
            );
        });

        Schema::create('consentimientos', function (Blueprint $tabla): void {
            $tabla->id();

            // El TITULAR de los datos, siempre. Aunque quien firme sea el tutor.
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            // La versión exacta que se firmó. RestrictOnDelete: borrar un texto
            // firmado dejaría consentimientos sin poder demostrar qué amparan.
            $tabla->foreignId('texto_consentimiento_id')
                ->constrained('textos_consentimiento')->restrictOnDelete();

            $tabla->foreignId('otorgado_por_persona_id')
                ->constrained('personas')->restrictOnDelete();
            $tabla->enum('relacion', ['titular', 'tutor']);

            // NULL = ampara a la plataforma, no a un tenant concreto.
            $tabla->organizacion(nullable: true);

            // FK lógica a `propositos`, que nace en M6 (Fase 5).
            $tabla->unsignedBigInteger('proposito_id')->nullable();

            /*
             * "Cualquier mecanismo de autenticación equivalente" a la firma
             * autógrafa (Doc 06 §3). El clic autenticado se documenta como tal
             * y por eso la evidencia se guarda tipificada.
             */
            $tabla->enum('evidencia', ['clic_firmado', 'firma_digital', 'documento']);
            $tabla->unsignedBigInteger('media_id')->nullable();

            $tabla->date('vigencia_inicio');
            $tabla->date('vigencia_fin')->nullable();
            $tabla->dateTime('revocado_en')->nullable();
            $tabla->string('motivo_revocacion', 255)->nullable();

            $tabla->enum('estado', [
                'vigente', 'vencido', 'revocado', 'pendiente_reconsentimiento',
            ])->default('vigente');

            $tabla->sellosDeTiempo();

            $tabla->index(['persona_id', 'estado'], 'consentimientos_persona_estado_index');
            $tabla->index(
                ['persona_id', 'organizacion_id', 'estado'],
                'consentimientos_ambito_index'
            );
        });

        Schema::create('comparticiones_expediente', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            $tabla->foreignId('organizacion_destino_id')
                ->constrained('organizaciones')->cascadeOnDelete();

            // FK lógica a `dominios`, que nace en M5 (Fase 3).
            // NULL = según el alcance, no acotado a un dominio.
            $tabla->unsignedBigInteger('dominio_id')->nullable();

            $tabla->foreignId('consentimiento_id')
                ->constrained('consentimientos')->cascadeOnDelete();

            $tabla->enum('alcance', ['resumen', 'detalle']);
            $tabla->date('vigencia_fin')->nullable();
            $tabla->dateTime('revocado_en')->nullable();
            $tabla->sellosDeTiempo();

            $tabla->index(
                ['persona_id', 'organizacion_destino_id'],
                'comparticiones_destino_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparticiones_expediente');
        Schema::dropIfExists('consentimientos');
        Schema::dropIfExists('textos_consentimiento');
        Schema::dropIfExists('tipos_consentimiento');
    }
};
