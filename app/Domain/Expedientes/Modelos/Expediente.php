<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El expediente psicométrico de vida. 1:1 con la persona y GLOBAL como ella.
 *
 * Lo que lleva contexto de tenant son los VALORES capturados, no el
 * expediente: la niña tamizada en primaria y la candidata de veintidós años
 * son la misma fila.
 *
 * @property int $id
 * @property int $persona_id
 * @property string $estado
 * @property string|null $motivo_bloqueo
 */
class Expediente extends Modelo
{
    protected $table = 'expedientes';

    protected $fillable = ['persona_id', 'estado', 'motivo_bloqueo'];

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return HasMany<ExpedienteValor, $this>
     */
    public function valores(): HasMany
    {
        return $this->hasMany(ExpedienteValor::class);
    }

    /**
     * @return HasMany<ExpedienteDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(ExpedienteDocumento::class);
    }

    /**
     * @return HasMany<NotaProfesional, $this>
     */
    public function notas(): HasMany
    {
        return $this->hasMany(NotaProfesional::class);
    }

    /**
     * Bloqueado = terceros no acceden. El TITULAR sí: es su dato.
     *
     * Lo pone la transición de mayoría de edad mientras la persona no
     * re-consienta (Doc 06 §3).
     */
    public function estaBloqueado(): bool
    {
        return $this->estado === 'bloqueado';
    }
}
