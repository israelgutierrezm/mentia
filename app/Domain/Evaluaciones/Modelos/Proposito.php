<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Consentimientos\Modelos\TipoConsentimiento;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La plantilla de asignación: para QUÉ se aplica.
 *
 * Es la FINALIDAD en el sentido de la LFPDPPP (Doc 06 §3). Un consentimiento
 * firmado para un tamizaje escolar no ampara un proceso de selección laboral, y
 * el propósito es lo que permite comprobarlo: `asignaciones.proposito_id` viaja
 * hasta AccesoService cuando alguien pide los resultados.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $clave
 * @property string $nombre
 * @property int|null $bateria_id
 * @property int|null $version_instrumento_id
 * @property int $tipo_consentimiento_id
 * @property int $vigencia_dias_default
 * @property string $modo_presentacion_default
 * @property bool $genera_reporte_integrador
 */
class Proposito extends Modelo
{
    protected $table = 'propositos';

    protected $fillable = [
        'organizacion_id', 'clave', 'nombre', 'bateria_id', 'version_instrumento_id',
        'tipo_consentimiento_id', 'vigencia_dias_default', 'modo_presentacion_default',
        'genera_reporte_integrador',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['genera_reporte_integrador' => 'boolean'];
    }

    /** @return BelongsTo<TipoConsentimiento, $this> */
    public function tipoConsentimiento(): BelongsTo
    {
        return $this->belongsTo(TipoConsentimiento::class, 'tipo_consentimiento_id');
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

    /**
     * @param  Builder<Proposito>  $consulta
     * @return Builder<Proposito>
     */
    public function scopeDisponiblesPara(Builder $consulta, ?int $organizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($organizacionId): void {
            $sub->whereNull('organizacion_id');

            if ($organizacionId !== null) {
                $sub->orWhere('organizacion_id', $organizacionId);
            }
        });
    }
}
