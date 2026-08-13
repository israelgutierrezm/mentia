<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un parámetro de una etapa del pipeline.
 *
 * Tabla hija y no columna JSON: que el umbral de omisiones de un instrumento
 * sea 20% tiene que poder consultarse y cambiarse con un UPDATE.
 *
 * @property int $id
 * @property int $instrumento_pipeline_id
 * @property string $clave
 * @property string $valor
 */
class ParametroPipeline extends Modelo
{
    protected $table = 'instrumento_pipeline_parametros';

    protected $fillable = ['instrumento_pipeline_id', 'clave', 'valor'];

    /** @return BelongsTo<EtapaPipeline, $this> */
    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaPipeline::class, 'instrumento_pipeline_id');
    }
}
