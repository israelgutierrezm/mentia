<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sección del expediente. Catálogo global.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property int $orden
 * @property int $nivel_sensibilidad_id
 */
class SeccionExpediente extends Modelo implements TieneSensibilidad
{
    protected $table = 'secciones_expediente';

    protected $fillable = ['clave', 'nombre', 'orden', 'nivel_sensibilidad_id'];

    /**
     * @return BelongsTo<NivelSensibilidad, $this>
     */
    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelSensibilidad::class, 'nivel_sensibilidad_id');
    }

    /**
     * @return HasMany<ExpedienteCampo, $this>
     */
    public function campos(): HasMany
    {
        return $this->hasMany(ExpedienteCampo::class, 'seccion_id');
    }

    public function nivelSensibilidad(): int
    {
        $nivel = $this->getRelationValue('nivel');

        return $nivel instanceof NivelSensibilidad ? $nivel->nivel : 4;
    }
}
