<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un permiso de una plantilla de rol, por NOMBRE.
 *
 * @property int $id
 * @property int $plantilla_rol_id
 * @property string $permiso
 */
class PlantillaRolPermiso extends Modelo
{
    protected $table = 'plantilla_rol_permisos';

    protected $fillable = ['plantilla_rol_id', 'permiso'];

    /**
     * @return BelongsTo<PlantillaRol, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaRol::class, 'plantilla_rol_id');
    }
}
