<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 03 §M2 — personas (GLOBAL, sin organizacion_id).
 *
 * La entidad raíz del sistema (principio P1). La persona NO pertenece a un
 * tenant: su expediente la acompaña de la primaria a la empresa. Lo que
 * pertenece a un tenant es la VINCULACIÓN (`organizacion_personas`), y lo que
 * cruza tenants lo decide la persona por consentimiento.
 *
 * Por eso esta tabla no lleva organizacion_id ni global scope. Que aparezca
 * aquí una organización sería el error que rompe el expediente de vida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $tabla): void {
            $tabla->id();

            /*
             * Identificador PÚBLICO. Hacia afuera —API, ligas, URLs— nunca
             * viaja el id: un id se cuenta, y quien pidiera 1, 2, 3… se
             * llevaría el padrón completo.
             */
            $tabla->uuid('uuid')->unique();

            /*
             * Ancla de identidad en México. Nullable a propósito: hay
             * extranjeros, menores sin trámite y casos de captura sin
             * documento. Única cuando existe — es lo que impide que la misma
             * persona nazca dos veces y termine con dos expedientes.
             */
            $tabla->char('curp', 18)->nullable()->unique();

            $tabla->string('nombres', 120);
            $tabla->string('primer_apellido', 80);
            $tabla->string('segundo_apellido', 80)->nullable();

            // Insumo de baremos por edad: la edad al aplicar decide la norma.
            $tabla->date('fecha_nacimiento');

            // Insumo de baremos. Es el sexo REGISTRAL, el del acta, porque es
            // el que usan las tablas normativas publicadas. No es identidad de
            // género, que es otro dato y va en el expediente.
            $tabla->enum('sexo_registral', ['M', 'F', 'X']);

            $tabla->enum('verificacion_identidad', [
                'no_verificada', 'documental', 'presencial',
            ])->default('no_verificada');

            $tabla->sellosDeTiempo();

            // Búsqueda por nombre en los listados de alta y vinculación.
            $tabla->index(['primer_apellido', 'segundo_apellido', 'nombres']);
            $tabla->index('fecha_nacimiento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
