<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una solicitud de derechos ARCO (Doc 06 §3 — LFPDPPP).
 *
 * No es un formulario de contacto: la ley fija plazos y exige respuesta
 * documentada. Una solicitud que se traspapela es un incumplimiento con
 * sanción.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organizacion_id
 * @property int $persona_id
 * @property int $presentada_por
 * @property string $derecho
 * @property string $descripcion
 * @property string $estado
 * @property Carbon $recibida_en
 * @property Carbon $vence_respuesta
 * @property Carbon|null $vence_cumplimiento
 * @property Carbon|null $respondida_en
 * @property int|null $respondida_por
 * @property string|null $respuesta
 * @property string|null $excepciones_aplicadas
 * @property int|null $media_id
 */
class SolicitudArco extends Modelo
{
    use PerteneceAOrganizacion;

    /** Días HÁBILES para responder si procede o no (LFPDPPP art. 32). */
    public const DIAS_RESPUESTA = 20;

    /** Días hábiles adicionales para hacerla efectiva (art. 32). */
    public const DIAS_CUMPLIMIENTO = 15;

    protected $table = 'solicitudes_arco';

    protected $fillable = [
        'uuid', 'organizacion_id', 'persona_id', 'presentada_por', 'derecho',
        'descripcion', 'estado', 'recibida_en', 'vence_respuesta',
        'vence_cumplimiento', 'respondida_en', 'respondida_por', 'respuesta',
        'excepciones_aplicadas', 'media_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recibida_en' => 'datetime',
            'vence_respuesta' => 'date',
            'vence_cumplimiento' => 'date',
            'respondida_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $solicitud): void {
            if ($solicitud->uuid === null) {
                $solicitud->uuid = (string) Str::uuid();
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

    /** @return BelongsTo<Persona, $this> */
    public function presentadaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'presentada_por');
    }

    public function estaAbierta(): bool
    {
        return ! in_array($this->estado, ['cumplida', 'improcedente'], true);
    }

    /**
     * ¿Se pasó el plazo de respuesta?
     *
     * Se compara contra HOY y no contra `respondida_en`: una solicitud
     * contestada tarde ya venció aunque tenga respuesta, y el registro tiene que
     * poder decirlo.
     */
    public function vencida(?Carbon $al = null): bool
    {
        if (! $this->estaAbierta()) {
            return false;
        }

        return $this->vence_respuesta->lessThan(($al ?? Carbon::now())->startOfDay());
    }

    /**
     * @param  Builder<SolicitudArco>  $consulta
     * @return Builder<SolicitudArco>
     */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->whereNotIn('estado', ['cumplida', 'improcedente']);
    }
}
