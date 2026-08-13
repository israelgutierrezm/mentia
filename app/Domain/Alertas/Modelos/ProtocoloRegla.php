<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Modelos;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Escalonamiento automático: qué pasa cuando un resultado cumple una condición.
 *
 * NO lleva el trait de tenant: con `organizacion_id` NULL es una regla de la
 * plataforma que aplica a todos, y el scope la escondería.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property int $si_version_instrumento_id
 * @property int $condicion_escala_id
 * @property string $tipo_puntaje
 * @property string $operador
 * @property string $valor
 * @property string $entonces_accion
 * @property int|null $entonces_ref_id
 * @property int|null $notificar_rol_id
 * @property string|null $nota
 * @property bool $activo
 */
class ProtocoloRegla extends Modelo
{
    protected $table = 'protocolo_reglas';

    protected $fillable = [
        'organizacion_id', 'si_version_instrumento_id', 'condicion_escala_id',
        'tipo_puntaje', 'operador', 'valor', 'entonces_accion', 'entonces_ref_id',
        'notificar_rol_id', 'nota', 'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'si_version_instrumento_id');
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class, 'condicion_escala_id');
    }

    /**
     * Las que aplican a una versión dentro de una organización: las suyas y las
     * de la plataforma.
     *
     * @param  Builder<ProtocoloRegla>  $consulta
     * @return Builder<ProtocoloRegla>
     */
    public function scopeAplicablesA(Builder $consulta, int $versionId, ?int $organizacionId): Builder
    {
        return $consulta
            ->where('activo', true)
            ->where('si_version_instrumento_id', $versionId)
            ->where(function (Builder $sub) use ($organizacionId): void {
                $sub->whereNull('organizacion_id');

                if ($organizacionId !== null) {
                    $sub->orWhere('organizacion_id', $organizacionId);
                }
            });
    }
}
