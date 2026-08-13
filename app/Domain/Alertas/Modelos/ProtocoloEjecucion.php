<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Modelos;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Constancia de que una regla de protocolo ya corrió sobre una aplicación.
 *
 * Existe para que recalificar no vuelva a disparar lo mismo. Sin ella, la
 * familia recibiría dos veces la liga de la entrevista de seguimiento y el
 * psicólogo dos veces la misma alarma — y a la tercera deja de mirarlas.
 *
 * @property int $id
 * @property int $protocolo_regla_id
 * @property int $aplicacion_id
 * @property string $resultado
 * @property Carbon $ejecutada_en
 */
class ProtocoloEjecucion extends Modelo
{
    protected $table = 'protocolo_ejecuciones';

    protected $fillable = [
        'protocolo_regla_id', 'aplicacion_id', 'resultado', 'ejecutada_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ejecutada_en' => 'datetime'];
    }

    /** @return BelongsTo<ProtocoloRegla, $this> */
    public function regla(): BelongsTo
    {
        return $this->belongsTo(ProtocoloRegla::class, 'protocolo_regla_id');
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }
}
