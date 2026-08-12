<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M3 — bitacora (APPEND-ONLY).
 *
 * Toda decisión de AccesoService se escribe aquí, incluidas las DENEGADAS. Las
 * denegadas son la mitad del valor: un intento repetido de ver el expediente de
 * alguien fuera de alcance es justo lo que un auditor busca, y si sólo se
 * registraran los accesos concedidos no quedaría rastro.
 *
 * Cumple el "registro de accesos a sensibles" del Doc 06 §4: quién vio qué
 * resultado, cuándo y con qué propósito.
 *
 * NO SE ACTUALIZA NI SE BORRA. A nivel de aplicación lo impide el modelo
 * Bitacora; en el despliegue, el usuario MySQL de la app va sin UPDATE ni
 * DELETE sobre esta tabla (Doc 06 §4). Las dos cosas: un modelo se puede
 * esquivar con un DB::table().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Sin FKs hacia organizaciones ni personas, a propósito.
             *
             * La bitácora sobrevive a lo que registra: si se borra una
             * organización o se ejerce una cancelación ARCO sobre una persona,
             * el rastro de quién accedió a qué NO puede irse en cascada — es
             * justo la evidencia que la ley obliga a conservar. Con FK, el
             * borrado se llevaría la prueba o quedaría bloqueado para siempre.
             */
            $tabla->unsignedBigInteger('organizacion_id')->nullable();
            $tabla->unsignedBigInteger('actor_persona_id')->nullable();

            $tabla->string('accion', 80);
            $tabla->string('recurso_tipo', 80);
            $tabla->unsignedBigInteger('recurso_id')->nullable();
            $tabla->unsignedBigInteger('persona_afectada_id')->nullable();

            // FK lógica a `propositos`, que nace en M6 (Fase 5).
            $tabla->unsignedBigInteger('proposito_id')->nullable();

            $tabla->enum('resultado', ['permitido', 'denegado']);

            // El motivo de la negativa: qué dimensión falló. Sin esto, una
            // bitácora de denegados dice que algo se negó y no por qué.
            $tabla->string('motivo', 160)->nullable();

            $tabla->string('ip', 45)->nullable();
            $tabla->string('user_agent', 255)->nullable();

            /*
             * datetime(3): milésimas. Un lote de respuestas produce varias
             * decisiones en el mismo segundo y el orden importa para
             * reconstruir qué pasó.
             *
             * No se usan creado_en/actualizado_en: una fila de bitácora no se
             * actualiza nunca, así que una columna `actualizado_en` sería una
             * promesa falsa.
             */
            $tabla->dateTime('registrado_en', precision: 3);

            $tabla->index(['persona_afectada_id', 'registrado_en']);
            $tabla->index(['actor_persona_id', 'registrado_en']);
            $tabla->index(['organizacion_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
