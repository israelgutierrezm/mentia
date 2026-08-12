<?php

declare(strict_types=1);

namespace App\Soporte\Multitenencia;

use App\Domain\Organizaciones\Modelos\Organizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como dato de tenant.
 *
 * Hace dos cosas, y las dos hacen falta:
 *
 * 1. Aplica el global scope, para que NINGUNA consulta pueda leer de otra
 *    organización aunque quien la escribió se olvide del `where`.
 * 2. Rellena `organizacion_id` al crear. Sin esto, un `create()` sin la
 *    columna la deja en null o —peor— con el valor que traía el arreglo, y una
 *    fila puede nacer en el tenant equivocado. El aislamiento tiene que
 *    cubrir la escritura, no sólo la lectura.
 *
 * @property int $organizacion_id
 */
trait PerteneceAOrganizacion
{
    public static function bootPerteneceAOrganizacion(): void
    {
        static::addGlobalScope(new AlcanceOrganizacion);

        static::creating(function (self $modelo): void {
            if ($modelo->organizacion_id !== null) {
                return;
            }

            $organizacionId = app(ContextoOrganizacion::class)->id();

            if ($organizacionId !== null) {
                $modelo->organizacion_id = $organizacionId;
            }
        });
    }

    /**
     * @return BelongsTo<Organizacion, $this>
     */
    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class);
    }
}
