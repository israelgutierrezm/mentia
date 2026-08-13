<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Documento del expediente. El archivo vive en medialibrary; esta fila es su
 * ficha: qué es, quién lo subió, si está validado y hasta cuándo sirve.
 *
 * @property int $id
 * @property int $expediente_id
 * @property int $tipo_documento_id
 * @property int|null $media_id
 * @property int|null $organizacion_id_contexto
 * @property int $cargado_por
 * @property string $estado
 * @property int|null $validado_por
 * @property Carbon|null $vigencia_fin
 */
class ExpedienteDocumento extends Modelo
{
    protected $table = 'expediente_documentos';

    protected $fillable = [
        'expediente_id',
        'tipo_documento_id',
        'media_id',
        'organizacion_id_contexto',
        'cargado_por',
        'estado',
        'validado_por',
        'vigencia_fin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['vigencia_fin' => 'date'];
    }

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<TipoDocumento, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'cargado_por');
    }

    public function estaVigente(?Carbon $al = null): bool
    {
        if ($this->estado !== 'validado') {
            return false;
        }

        return $this->vigencia_fin === null
            || $this->vigencia_fin->endOfDay()->greaterThanOrEqualTo($al ?? Carbon::now());
    }
}
