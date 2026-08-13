<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El resultado de UNA verificación de validez, con su porqué.
 *
 * El detalle es lo que hace defendible la decisión: "18 de 21 sin responder
 * (86%)" se puede discutir con la persona; "inválida" a secas, no.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property string $verificacion
 * @property string $resultado
 * @property string $detalle
 */
class ValidezDetalle extends Modelo
{
    protected $table = 'validez_detalle';

    protected $fillable = ['aplicacion_id', 'verificacion', 'resultado', 'detalle'];

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }
}
