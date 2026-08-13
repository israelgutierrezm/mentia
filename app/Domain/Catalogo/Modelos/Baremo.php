<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de baremo.
 *
 * NO lleva el trait de tenant: con `organizacion_id` NULL es el baremo global
 * publicado y el scope lo escondería, dejando sin norma a todo el mundo.
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int $escala_id
 * @property int $poblacion_norma_id
 * @property int|null $organizacion_id
 * @property string $tipo_norma
 * @property bool $vigente
 */
class Baremo extends Modelo
{
    protected $table = 'baremos';

    protected $fillable = [
        'version_instrumento_id', 'escala_id', 'poblacion_norma_id',
        'organizacion_id', 'tipo_norma', 'vigente', 'fuente',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }

    /** @return BelongsTo<Escala, $this> */
    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class);
    }

    /** @return BelongsTo<PoblacionNorma, $this> */
    public function poblacion(): BelongsTo
    {
        return $this->belongsTo(PoblacionNorma::class, 'poblacion_norma_id');
    }

    /** @return HasMany<BaremoFila, $this> */
    public function filas(): HasMany
    {
        return $this->hasMany(BaremoFila::class)->orderBy('bruto_min');
    }

    public function esPropioDeTenant(): bool
    {
        return $this->organizacion_id !== null;
    }
}
