<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reactivo_id
 * @property string $codigo
 * @property string $texto
 * @property int|null $media_id
 * @property int|null $organizacion_id_contenido
 * @property bool|null $es_correcta
 * @property int $orden
 */
class OpcionReactivo extends Modelo
{
    protected $table = 'opciones_reactivo';

    protected $fillable = [
        'reactivo_id', 'codigo', 'texto', 'media_id',
        'organizacion_id_contenido', 'es_correcta', 'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['es_correcta' => 'boolean'];
    }

    /** @return BelongsTo<Reactivo, $this> */
    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class);
    }
}
