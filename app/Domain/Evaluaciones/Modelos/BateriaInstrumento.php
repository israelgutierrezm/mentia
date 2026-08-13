<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un instrumento dentro de una batería.
 *
 * `obligatorio` en false permite el instrumento que sólo aplica a parte de la
 * población: si no se contesta, la batería igual se da por completa.
 *
 * @property int $id
 * @property int $bateria_id
 * @property int $version_instrumento_id
 * @property int $orden
 * @property bool $obligatorio
 */
class BateriaInstrumento extends Modelo
{
    protected $table = 'bateria_instrumentos';

    protected $fillable = ['bateria_id', 'version_instrumento_id', 'orden', 'obligatorio'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['obligatorio' => 'boolean'];
    }

    /** @return BelongsTo<Bateria, $this> */
    public function bateria(): BelongsTo
    {
        return $this->belongsTo(Bateria::class);
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }
}
