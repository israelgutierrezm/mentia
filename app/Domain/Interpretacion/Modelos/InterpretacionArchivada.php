<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El texto de interpretación tal como se entregó antes de recalificar.
 *
 * @property int $id
 * @property int $resultado_archivado_id
 * @property string $audiencia
 * @property string $texto_resuelto
 * @property string|null $bandera
 * @property int $orden
 */
class InterpretacionArchivada extends Modelo
{
    protected $table = 'resultado_archivado_interpretacion';

    protected $fillable = [
        'resultado_archivado_id', 'audiencia', 'texto_resuelto', 'bandera', 'orden',
    ];

    /** @return BelongsTo<ResultadoArchivado, $this> */
    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ResultadoArchivado::class, 'resultado_archivado_id');
    }
}
