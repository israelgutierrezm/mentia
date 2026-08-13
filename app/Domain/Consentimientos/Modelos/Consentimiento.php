<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un consentimiento otorgado.
 *
 * NO lleva el trait de tenant: con `organizacion_id` NULL ampara a la
 * plataforma, y el global scope lo escondería justo cuando más falta hace. El
 * acotamiento se hace en las consultas del verificador, que sabe qué ámbito
 * está preguntando.
 *
 * @property int $id
 * @property int $persona_id
 * @property int $texto_consentimiento_id
 * @property int $otorgado_por_persona_id
 * @property string $relacion
 * @property int|null $organizacion_id
 * @property int|null $proposito_id
 * @property string $evidencia
 * @property int|null $media_id
 * @property Carbon $vigencia_inicio
 * @property Carbon|null $vigencia_fin
 * @property Carbon|null $revocado_en
 * @property string|null $motivo_revocacion
 * @property string $estado
 */
class Consentimiento extends Modelo
{
    protected $table = 'consentimientos';

    protected $fillable = [
        'persona_id',
        'texto_consentimiento_id',
        'otorgado_por_persona_id',
        'relacion',
        'organizacion_id',
        'proposito_id',
        'evidencia',
        'media_id',
        'vigencia_inicio',
        'vigencia_fin',
        'revocado_en',
        'motivo_revocacion',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'date',
            'vigencia_fin' => 'date',
            'revocado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<TextoConsentimiento, $this>
     */
    public function texto(): BelongsTo
    {
        return $this->belongsTo(TextoConsentimiento::class, 'texto_consentimiento_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function otorgadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'otorgado_por_persona_id');
    }

    /**
     * Vigente = estado vigente Y dentro de fechas Y sin revocar.
     *
     * Las tres. El estado es lo que dejaron los jobs nocturnos; las fechas son
     * la verdad del momento, y entre una corrida y otra del job pueden diferir.
     * Preguntar sólo por el estado haría que un consentimiento vencido a
     * medianoche siguiera amparando accesos hasta que el job pase.
     */
    public function estaVigente(?Carbon $al = null): bool
    {
        if ($this->estado !== 'vigente' || $this->revocado_en !== null) {
            return false;
        }

        $fecha = $al ?? Carbon::now();

        if ($this->vigencia_inicio->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_fin === null
            || $this->vigencia_fin->endOfDay()->greaterThanOrEqualTo($fecha);
    }

    /**
     * @param  Builder<Consentimiento>  $consulta
     * @return Builder<Consentimiento>
     */
    public function scopeVigentes(Builder $consulta, ?Carbon $al = null): Builder
    {
        $fecha = $al ?? Carbon::now();

        return $consulta
            ->where('estado', 'vigente')
            ->whereNull('revocado_en')
            ->whereDate('vigencia_inicio', '<=', $fecha)
            ->where(function (Builder $sub) use ($fecha): void {
                $sub->whereNull('vigencia_fin')
                    ->orWhereDate('vigencia_fin', '>=', $fecha);
            });
    }
}
