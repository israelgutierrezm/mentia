<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una etapa del pipeline de una versión de instrumento (Doc 05 §1.3).
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property string $etapa
 * @property string $estrategia_clave
 * @property int $orden
 * @property bool $activa
 */
class EtapaPipeline extends Modelo
{
    protected $table = 'instrumento_pipeline';

    protected $fillable = [
        'version_instrumento_id', 'etapa', 'estrategia_clave', 'orden', 'activa',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return HasMany<ParametroPipeline, $this> */
    public function parametros(): HasMany
    {
        return $this->hasMany(ParametroPipeline::class, 'instrumento_pipeline_id');
    }

    /**
     * Los parámetros como mapa clave → valor, listos para la estrategia.
     *
     * @return array<string, string>
     */
    public function parametrosComoMapa(): array
    {
        /** @var array<string, string> $mapa */
        $mapa = $this->parametros
            ->mapWithKeys(static fn (ParametroPipeline $parametro): array => [
                $parametro->clave => $parametro->valor,
            ])->all();

        return $mapa;
    }
}
