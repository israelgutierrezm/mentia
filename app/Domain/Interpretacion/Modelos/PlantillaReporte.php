<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;

/**
 * La estructura de un reporte, por tipo y AUDIENCIA.
 *
 * La audiencia es parte de la identidad de la plantilla, no un filtro: el
 * reporte para el profesional y el del evaluado no son el mismo documento con
 * distinto formato, son documentos distintos.
 *
 * No lleva el trait de tenant: con `organizacion_id` NULL es la plantilla del
 * sistema, y el scope la escondería dejando sin reporte a quien no adaptó la
 * suya.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $tipo
 * @property string $audiencia
 * @property int|null $version_instrumento_id
 * @property int|null $bateria_id
 * @property string $estructura_html
 * @property bool $vigente
 */
class PlantillaReporte extends Modelo
{
    protected $table = 'plantillas_reporte';

    protected $fillable = [
        'organizacion_id', 'tipo', 'audiencia', 'version_instrumento_id',
        'bateria_id', 'estructura_html', 'vigente',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }

    /**
     * La plantilla que aplica: la del tenant si la tiene, si no la del sistema.
     *
     * La resolución es la misma idea que la de baremos: lo propio le gana a lo
     * general, y lo general existe para que nunca falte.
     */
    public static function resolver(
        string $tipo,
        string $audiencia,
        ?int $organizacionId,
        ?int $versionInstrumentoId = null,
    ): ?self {
        $candidatas = self::query()
            ->where('tipo', $tipo)
            ->where('audiencia', $audiencia)
            ->where('vigente', true)
            ->where(function (Builder $consulta) use ($organizacionId): void {
                $consulta->whereNull('organizacion_id');

                if ($organizacionId !== null) {
                    $consulta->orWhere('organizacion_id', $organizacionId);
                }
            })
            ->get();

        if ($candidatas->isEmpty()) {
            return null;
        }

        /*
         * Con instrumento pedido, la plantilla ESPECÍFICA de ese instrumento le
         * gana a la genérica: un reporte de NOM-035 tiene formato oficial y no
         * se puede dibujar con la plantilla de cualquier cosa.
         */
        $orden = [
            fn (self $p): bool => $p->organizacion_id !== null && $p->version_instrumento_id === $versionInstrumentoId,
            fn (self $p): bool => $p->organizacion_id !== null && $p->version_instrumento_id === null,
            fn (self $p): bool => $p->organizacion_id === null && $p->version_instrumento_id === $versionInstrumentoId,
            fn (self $p): bool => $p->organizacion_id === null && $p->version_instrumento_id === null,
        ];

        foreach ($orden as $criterio) {
            $encontrada = $candidatas->first($criterio);

            if ($encontrada instanceof self) {
                return $encontrada;
            }
        }

        return null;
    }
}
