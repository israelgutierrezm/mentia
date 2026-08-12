<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tope de sensibilidad por rol.
 *
 * @property int $rol_id
 * @property int $nivel_sensibilidad_max
 */
class RolSensibilidadMax extends Modelo
{
    protected $table = 'rol_sensibilidad_max';

    protected $primaryKey = 'rol_id';

    public $incrementing = false;

    protected $fillable = ['rol_id', 'nivel_sensibilidad_max'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nivel_sensibilidad_max' => 'integer'];
    }

    /**
     * @return BelongsTo<Rol, $this>
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
