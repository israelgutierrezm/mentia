<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M7 — `respuestas`: LA TABLA CRÍTICA.
 *
 * Proyección de decenas de millones de filas (Doc 02 §7). Todo lo de aquí está
 * pensado para eso: una fila por reactivo respondido, sin JSON, con los índices
 * exactos que la reportería y el pipeline necesitan y ninguno más —un índice de
 * más se paga en cada escritura, para siempre—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respuestas', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $tabla->foreignId('reactivo_id')->constrained('reactivos')->restrictOnDelete();

            // Una columna por tipo de valor. Guardar todo como texto haría
            // imposible sumar sin convertir en cada consulta.
            $tabla->foreignId('opcion_id')->nullable()
                ->constrained('opciones_reactivo')->restrictOnDelete();
            $tabla->decimal('valor_numerico', 10, 3)->nullable();

            /*
             * CIFRADO a nivel aplicación (Doc 06 §4): una respuesta abierta
             * puede contener cualquier cosa que la persona haya querido
             * escribir, y en un tamizaje clínico eso incluye lo más delicado
             * de su expediente.
             */
            $tabla->text('valor_texto')->nullable();

            $tabla->unsignedBigInteger('media_id')->nullable();

            // Cleaver y demás ipsativos: la misma opción marcada como "más" o
            // como "menos" dentro de su cuadro.
            $tabla->enum('rol_ipsativo', ['mas', 'menos'])->nullable();

            $tabla->unsignedTinyInteger('posicion_ranking')->nullable();

            /*
             * IDEMPOTENCIA. El cliente genera el uuid ANTES de mandar, así que
             * reintentar un lote que se perdió en la red no duplica nada.
             *
             * Es lo que hace posible el modo offline de la V3: el teléfono
             * acumula respuestas con su uuid y las sincroniza sin miedo a
             * mandar dos veces.
             */
            $tabla->char('uuid_cliente', 36)->unique();

            /*
             * "Oro conductual" (Doc 03 §M7). El tiempo por reactivo detecta
             * respuesta al azar, asistencia externa y fatiga; es insumo directo
             * de la etapa 1 del pipeline.
             */
            $tabla->unsignedInteger('tiempo_respuesta_ms')->nullable();

            $tabla->dateTime('respondida_en', precision: 3);
            $tabla->enum('origen', ['online', 'offline'])->default('online');

            /*
             * TARDÍA: llegó después de que su bloque expirara. No se rechaza
             * —perder la respuesta sería peor— pero se marca, porque una
             * prueba cronometrada contestada fuera de tiempo no puntúa igual.
             * La decisión de qué hacer con ellas es del pipeline, no de aquí.
             */
            $tabla->boolean('tardia')->default(false);

            $tabla->sellosDeTiempo();

            /*
             * Único por (aplicacion, reactivo) SALVO ranking, que necesita una
             * fila por opción. Se resuelve con un único de tres columnas donde
             * `opcion_id` es NULL en todo lo que no es ranking: MySQL admite
             * varios NULL en un índice único, así que para un likert el par
             * (aplicacion, reactivo) sigue siendo único de facto... y no basta.
             *
             * Por eso van los DOS: el de tres columnas para ranking y la
             * comprobación en el servicio para el resto. Un único de dos
             * columnas impediría el ranking; sólo el de tres dejaría duplicar
             * un likert cambiando la opción.
             */
            $tabla->unique(
                ['aplicacion_id', 'reactivo_id', 'opcion_id'],
                'respuestas_unica'
            );

            $tabla->index('reactivo_id');
            $tabla->index(['aplicacion_id', 'tardia'], 'respuestas_tardias_index');
        });

        Schema::create('capturas_protocolo', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $tabla->foreignId('escala_id')->constrained('escalas')->restrictOnDelete();

            /*
             * El examinador registra PUNTAJES, no respuestas: para un WISC o
             * un ADOS-2 la editorial prohíbe la aplicación en línea, así que lo
             * que entra al sistema es el resultado del protocolo de papel. El
             * pipeline arranca desde la etapa de normalización.
             */
            $tabla->decimal('puntaje_bruto', 10, 3);
            $tabla->decimal('puntaje_escalar', 10, 3)->nullable();

            $tabla->text('observaciones')->nullable();
            $tabla->foreignId('capturado_por')->constrained('personas')->restrictOnDelete();

            $tabla->sellosDeTiempo();

            $tabla->unique(['aplicacion_id', 'escala_id'], 'captura_escala_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capturas_protocolo');
        Schema::dropIfExists('respuestas');
    }
};
