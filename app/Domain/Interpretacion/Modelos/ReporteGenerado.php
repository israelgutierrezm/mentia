<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un reporte generado: un DOCUMENTO ENTREGADO.
 *
 * Se guarda el PDF y no se regenera al vuelo. Si el catálogo cambia mañana, el
 * papel que alguien tiene en la mano tiene que seguir explicándose.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organizacion_id
 * @property string $tipo
 * @property string $audiencia
 * @property int|null $persona_id
 * @property int|null $asignacion_id
 * @property int|null $aplicacion_id
 * @property int|null $plantilla_id
 * @property int|null $media_id
 * @property int $generado_por
 * @property Carbon $generado_en
 * @property int|null $firmado_por
 * @property Carbon|null $firmado_en
 */
class ReporteGenerado extends Modelo implements TieneSensibilidad
{
    use PerteneceAOrganizacion;

    protected $table = 'reportes_generados';

    protected $fillable = [
        'uuid', 'organizacion_id', 'tipo', 'audiencia', 'persona_id',
        'asignacion_id', 'aplicacion_id', 'plantilla_id', 'media_id',
        'generado_por', 'generado_en', 'firmado_por', 'firmado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['generado_en' => 'datetime', 'firmado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reporte): void {
            if ($reporte->uuid === null) {
                $reporte->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /** @return HasOne<ReporteIa, $this> */
    public function borradorIa(): HasOne
    {
        return $this->hasOne(ReporteIa::class, 'reporte_generado_id');
    }

    public function estaFirmado(): bool
    {
        return $this->firmado_por !== null;
    }

    /**
     * Un reporte hereda la sensibilidad de lo que contiene, y sin saberlo se
     * asume la máxima: es material clínico hasta que se demuestre lo contrario.
     */
    public function nivelSensibilidad(): int
    {
        return 4;
    }
}
