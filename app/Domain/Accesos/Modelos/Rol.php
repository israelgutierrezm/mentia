<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Permission\Models\Role;

/**
 * El rol de Spatie, con lo que Mentia le agrega encima.
 *
 * Hereda de Spatie —no lo reemplaza— para no perder el modo teams ni la caché
 * de permisos. Lo que añade es el tope de sensibilidad, que es la dimensión 3
 * de la autorización y no existe en el paquete.
 *
 * Sus sellos de tiempo siguen en inglés (`created_at`) porque la tabla `roles`
 * es del paquete y su migración es suya.
 */
class Rol extends Role
{
    /**
     * @return HasOne<RolSensibilidadMax, $this>
     */
    public function topeSensibilidad(): HasOne
    {
        return $this->hasOne(RolSensibilidadMax::class, 'rol_id');
    }

    /**
     * El nivel máximo de sensibilidad que este rol alcanza.
     *
     * Sin fila de tope, el rol se queda en 1 (general). Falla cerrado a
     * propósito: un rol al que se le olvidó configurar el tope no puede
     * terminar viendo resultados clínicos.
     */
    public function nivelSensibilidadMaximo(): int
    {
        // getRelationValue() usa la relación ya cargada si viene con `with()`
        // y la consulta sólo cuando no. El instanceof no es decoración: la
        // fila de tope puede no existir, y ese caso es el que cae en 1.
        $tope = $this->getRelationValue('topeSensibilidad');

        return $tope instanceof RolSensibilidadMax
            ? $tope->nivel_sensibilidad_max
            : 1;
    }
}
