<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Escala calculada a partir de otras.
 *
 * La expresión va sobre CLAVES de escala, no sobre ids: una fórmula escrita
 * con claves se lee y se corrige; con ids no. Se valida al publicar y NUNCA se
 * evalúa con eval() — una expresión de catálogo ejecutándose como PHP sería
 * ejecución remota de código servida desde una hoja de Excel.
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int $escala_destino_id
 * @property string $expresion
 * @property int $orden_evaluacion
 */
class FormulaDerivada extends Modelo
{
    protected $table = 'formulas_derivadas';

    protected $fillable = [
        'version_instrumento_id', 'escala_destino_id', 'expresion', 'orden_evaluacion',
    ];

    /** @return BelongsTo<Escala, $this> */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(Escala::class, 'escala_destino_id');
    }
}
