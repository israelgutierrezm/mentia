<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un puntaje capturado del protocolo de papel.
 *
 * El pipeline arranca desde la etapa de NORMALIZACIÓN con estas filas: no hay
 * respuestas que sumar porque el bruto ya viene dado por el examinador.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property int $escala_id
 * @property string $puntaje_bruto
 * @property string|null $puntaje_escalar
 * @property string|null $observaciones
 * @property int $capturado_por
 */
class CapturaProtocoloEscala extends Modelo
{
    protected $table = 'capturas_protocolo';

    protected $fillable = [
        'aplicacion_id', 'escala_id', 'puntaje_bruto', 'puntaje_escalar',
        'observaciones', 'capturado_por',
    ];

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

    /** @return BelongsTo<Persona, $this> */
    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'capturado_por');
    }
}
