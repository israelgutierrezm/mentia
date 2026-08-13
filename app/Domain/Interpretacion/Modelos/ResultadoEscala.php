<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Catalogo\Modelos\Baremo;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El puntaje de una escala en una aplicación: bruto y, si hay baremo, normalizado.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property int $escala_id
 * @property float $puntaje_bruto
 * @property int|null $baremo_id
 * @property float|null $valor_normalizado
 * @property string|null $tipo_norma
 * @property string|null $etiqueta_norma
 * @property bool $sin_norma
 * @property Carbon $calculado_en
 */
class ResultadoEscala extends Modelo
{
    protected $table = 'resultados_escala';

    protected $fillable = [
        'aplicacion_id', 'escala_id', 'puntaje_bruto', 'baremo_id',
        'valor_normalizado', 'tipo_norma', 'etiqueta_norma', 'sin_norma',
        'calculado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'puntaje_bruto' => 'float',
            'valor_normalizado' => 'float',
            'sin_norma' => 'boolean',
            'calculado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    /** @return BelongsTo<Baremo, $this> */
    public function baremo(): BelongsTo
    {
        return $this->belongsTo(Baremo::class);
    }

    /**
     * El valor con el que se evalúan las reglas de interpretación.
     *
     * Una regla declara en qué TIPO DE PUNTAJE está escrita: "percentil > 85"
     * no es lo mismo que "bruto > 85". Preguntar por el tipo equivocado es
     * comparar contra otra escala de medida sin que nada proteste.
     */
    public function valorEnTipo(string $tipoPuntaje): ?float
    {
        if ($tipoPuntaje === 'bruto') {
            return $this->puntaje_bruto;
        }

        // El catálogo de reglas dice 'ci'; los baremos dicen 'ci_desviacion'.
        $equivalente = $tipoPuntaje === 'ci' ? 'ci_desviacion' : $tipoPuntaje;

        if ($this->tipo_norma !== $equivalente) {
            return null;
        }

        return $this->valor_normalizado;
    }
}
