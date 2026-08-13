<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $version_instrumento_id
 * @property string $clave
 * @property string $nombre
 * @property int|null $escala_padre_id
 * @property bool $es_validez
 * @property int $orden
 */
class Escala extends Modelo
{
    protected $table = 'escalas';

    protected $fillable = [
        'version_instrumento_id', 'clave', 'nombre', 'escala_padre_id', 'es_validez', 'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['es_validez' => 'boolean'];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return BelongsTo<Escala, $this> */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'escala_padre_id');
    }

    /** @return HasMany<Escala, $this> */
    public function subescalas(): HasMany
    {
        return $this->hasMany(self::class, 'escala_padre_id');
    }
}
