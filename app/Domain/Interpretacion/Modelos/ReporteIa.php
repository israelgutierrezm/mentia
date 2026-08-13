<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El borrador que redactó la IA.
 *
 * NACE COMO BORRADOR Y NO HAY FORMA DE QUE NAZCA DE OTRA MANERA. La IA no
 * califica, no diagnostica y no libera nada: redacta sobre interpretaciones que
 * ya resolvió el motor, y una persona con cédula decide si eso se entrega
 * (principio P6 y Doc 05 §6).
 *
 * @property int $id
 * @property int $reporte_generado_id
 * @property string $modelo
 * @property string $insumo_hash
 * @property string $borrador
 * @property string $estado
 * @property int|null $validado_por
 * @property Carbon|null $validado_en
 * @property string|null $observaciones_validacion
 */
class ReporteIa extends Modelo
{
    protected $table = 'reportes_ia';

    protected $fillable = [
        'reporte_generado_id', 'modelo', 'insumo_hash', 'borrador',
        'estado', 'validado_por', 'validado_en', 'observaciones_validacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['validado_en' => 'datetime'];
    }

    /** @return BelongsTo<ReporteGenerado, $this> */
    public function reporte(): BelongsTo
    {
        return $this->belongsTo(ReporteGenerado::class, 'reporte_generado_id');
    }

    /** @return BelongsTo<Persona, $this> */
    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'validado_por');
    }

    public function estaValidado(): bool
    {
        return $this->estado === 'validado';
    }
}
