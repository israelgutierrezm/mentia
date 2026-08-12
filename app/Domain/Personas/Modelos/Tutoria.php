<?php

declare(strict_types=1);

namespace App\Domain\Personas\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quién puede actuar en nombre de un menor.
 *
 * @property int $id
 * @property int $tutor_persona_id
 * @property int $menor_persona_id
 * @property string $parentesco
 * @property int|null $documento_media_id
 * @property string $estado
 * @property Carbon $vigencia_inicio
 * @property Carbon|null $vigencia_fin
 * @property int|null $validada_por
 */
class Tutoria extends Modelo
{
    protected $table = 'tutorias';

    protected $fillable = [
        'tutor_persona_id',
        'menor_persona_id',
        'parentesco',
        'documento_media_id',
        'estado',
        'vigencia_inicio',
        'vigencia_fin',
        'validada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'date',
            'vigencia_fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'tutor_persona_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function menor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'menor_persona_id');
    }

    /**
     * Vigente = validada Y dentro de fechas.
     *
     * Las dos condiciones. El estado `pendiente_validacion` es el caso que más
     * importa: quien se registra declarando ser la madre no acredita nada hasta
     * que alguien lo valida, y darle acceso mientras tanto sería entregar el
     * expediente psicológico de un menor a un desconocido.
     */
    public function estaVigente(?Carbon $al = null): bool
    {
        if ($this->estado !== 'vigente') {
            return false;
        }

        $fecha = $al ?? Carbon::now();

        if ($this->vigencia_inicio->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_fin === null
            || $this->vigencia_fin->greaterThanOrEqualTo($fecha);
    }

    /**
     * @param  Builder<Tutoria>  $consulta
     * @return Builder<Tutoria>
     */
    public function scopeVigentes(Builder $consulta, ?Carbon $al = null): Builder
    {
        $fecha = $al ?? Carbon::now();

        return $consulta->where('estado', 'vigente')
            ->whereDate('vigencia_inicio', '<=', $fecha)
            ->where(function (Builder $sub) use ($fecha): void {
                $sub->whereNull('vigencia_fin')
                    ->orWhereDate('vigencia_fin', '>=', $fecha);
            });
    }
}
