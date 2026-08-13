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
 * @property string $titulo
 * @property string|null $instrucciones
 * @property int $orden
 * @property int|null $tiempo_limite_seg
 * @property string $orden_reactivos
 * @property bool $es_practica
 */
class Bloque extends Modelo
{
    protected $table = 'bloques';

    protected $fillable = [
        'version_instrumento_id', 'clave', 'titulo', 'instrucciones', 'orden',
        'tiempo_limite_seg', 'orden_reactivos', 'es_practica',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['es_practica' => 'boolean'];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return HasMany<Reactivo, $this> */
    public function reactivos(): HasMany
    {
        return $this->hasMany(Reactivo::class)->orderBy('orden');
    }

    public function tieneCronometro(): bool
    {
        return $this->tiempo_limite_seg !== null;
    }
}
