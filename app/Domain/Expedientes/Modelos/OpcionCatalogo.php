<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una opción. Se APAGA, no se borra: sigue estando en los valores históricos
 * que la eligieron.
 *
 * @property int $id
 * @property int $catalogo_opciones_id
 * @property string $clave
 * @property string $etiqueta
 * @property int $orden
 * @property bool $activo
 */
class OpcionCatalogo extends Modelo
{
    protected $table = 'opciones_catalogo';

    protected $fillable = ['catalogo_opciones_id', 'clave', 'etiqueta', 'orden', 'activo'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * @return BelongsTo<CatalogoOpciones, $this>
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoOpciones::class, 'catalogo_opciones_id');
    }
}
