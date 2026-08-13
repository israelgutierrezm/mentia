<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El puntaje de una escala tal como estaba antes de recalificar.
 *
 * Guarda la CLAVE de la escala además del id: una recalificación puede venir de
 * haber publicado una versión nueva del instrumento, y entonces la escala vieja
 * puede no existir.
 *
 * @property int $id
 * @property int $resultado_archivado_id
 * @property int $escala_id
 * @property string $escala_clave
 * @property float $puntaje_bruto
 * @property int|null $baremo_id
 * @property float|null $valor_normalizado
 * @property string|null $tipo_norma
 * @property string|null $etiqueta_norma
 * @property bool $sin_norma
 */
class EscalaArchivada extends Modelo
{
    protected $table = 'resultado_archivado_escala';

    protected $fillable = [
        'resultado_archivado_id', 'escala_id', 'escala_clave', 'puntaje_bruto',
        'baremo_id', 'valor_normalizado', 'tipo_norma', 'etiqueta_norma', 'sin_norma',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'puntaje_bruto' => 'float',
            'valor_normalizado' => 'float',
            'sin_norma' => 'boolean',
        ];
    }

    /** @return BelongsTo<ResultadoArchivado, $this> */
    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ResultadoArchivado::class, 'resultado_archivado_id');
    }
}
