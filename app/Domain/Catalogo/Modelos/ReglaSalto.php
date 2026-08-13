<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salto condicional.
 *
 * Se resuelve SIEMPRE en el servidor (Doc 02 §2): mandarle al cliente el árbol
 * de saltos le entregaría el mapa completo del instrumento, que es justo lo
 * que la entrega parcelada evita.
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int $reactivo_origen_id
 * @property int|null $opcion_id
 * @property string $condicion
 * @property string|null $valor
 * @property string $destino_tipo
 * @property int|null $destino_id
 */
class ReglaSalto extends Modelo
{
    protected $table = 'reglas_salto';

    protected $fillable = [
        'version_instrumento_id', 'reactivo_origen_id', 'opcion_id',
        'condicion', 'valor', 'destino_tipo', 'destino_id',
    ];

    /** @return BelongsTo<Reactivo, $this> */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class, 'reactivo_origen_id');
    }
}
