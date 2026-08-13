<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una foto del resultado ANTES de recalificar.
 *
 * Se escribe una vez y no se toca. Es lo que permite explicar, seis meses
 * después, con qué números se tomó una decisión — aunque el baremo con el que
 * se calcularon ya no exista.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property string $motivo
 * @property string $validez
 * @property string|null $motivo_invalidez
 * @property int $version_archivo
 * @property Carbon $archivado_en
 */
class ResultadoArchivado extends Modelo
{
    public $timestamps = false;

    protected $table = 'resultados_archivados';

    protected $fillable = [
        'aplicacion_id', 'motivo', 'validez', 'motivo_invalidez',
        'version_archivo', 'archivado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archivado_en' => 'datetime'];
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }

    /** @return HasMany<EscalaArchivada, $this> */
    public function escalas(): HasMany
    {
        return $this->hasMany(EscalaArchivada::class, 'resultado_archivado_id');
    }

    /** @return HasMany<InterpretacionArchivada, $this> */
    public function interpretaciones(): HasMany
    {
        return $this->hasMany(InterpretacionArchivada::class, 'resultado_archivado_id');
    }
}
