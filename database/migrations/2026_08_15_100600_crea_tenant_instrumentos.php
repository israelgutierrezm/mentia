<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M5 — habilitación de instrumentos por tenant.
 *
 * Que un instrumento exista en el catálogo NO significa que una organización
 * pueda aplicarlo. Esta tabla es la puerta, y su estado depende del
 * licenciamiento (principio P8):
 *
 *  - Dominio público: se habilita directo, el contenido ya está.
 *  - Requiere licencia: la organización DECLARA que tiene la licencia, y sólo
 *    entonces puede capturar los reactivos. Queda quién firmó y cuándo, que es
 *    la cadena de responsabilidad que exige el Doc 06 §3.
 *  - Sólo captura: nunca se aplica en línea; se capturan resultados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_instrumentos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->organizacion();
            $tabla->foreignId('version_instrumento_id')
                ->constrained('versiones_instrumento')->cascadeOnDelete();

            /*
             * - disponible:           está en el catálogo, nadie lo encendió.
             * - habilitado:           se puede asignar.
             * - pendiente_contenido:  licencia declarada, faltan reactivos.
             * - bloqueado:            la plataforma lo apagó para este tenant.
             */
            $tabla->enum('estado', [
                'disponible', 'habilitado', 'pendiente_contenido', 'bloqueado',
            ])->default('disponible');

            $tabla->enum('origen_contenido', ['global', 'capturado_por_tenant'])
                ->default('global');

            /*
             * El texto EXACTO de la declaración que se firmó, no un booleano.
             * Ante una reclamación editorial, "el tenant marcó una casilla" no
             * es defensa; el texto firmado con nombre y fecha sí.
             */
            $tabla->mediumText('declaracion_licencia_texto')->nullable();
            $tabla->foreignId('declaracion_firmada_por')->nullable()
                ->constrained('personas')->nullOnDelete();
            $tabla->dateTime('declaracion_firmada_en')->nullable();
            $tabla->unsignedBigInteger('evidencia_media_id')->nullable();

            $tabla->dateTime('habilitado_en')->nullable();
            $tabla->sellosDeTiempo();

            $tabla->unique(
                ['organizacion_id', 'version_instrumento_id'],
                'tenant_instrumento_unico'
            );
            $tabla->index(['organizacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_instrumentos');
    }
};
