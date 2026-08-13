<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una versión del instrumento. INMUTABLE tras publicarse (principio P4).
 *
 * Una aplicación de hace dos años apunta a esta versión exacta. Si su
 * contenido cambiara —un reactivo reformulado, un peso corregido, un corte
 * movido— su resultado dejaría de ser reproducible, y con él toda la serie
 * longitudinal de esa persona.
 *
 * Lo que hay que impedir no es cambiar el estado de esta fila, sino ESCRIBIR
 * su contenido. Eso lo hace PublicadorVersion y lo vigilan las pruebas.
 *
 * @property int $id
 * @property int $instrumento_id
 * @property string $version
 * @property string $idioma
 * @property string $estado
 * @property Carbon|null $publicada_en
 * @property string|null $notas_version
 */
class VersionInstrumento extends Modelo
{
    public const BORRADOR = 'borrador';

    public const PUBLICADA = 'publicada';

    public const RETIRADA = 'retirada';

    protected $table = 'versiones_instrumento';

    protected $fillable = [
        'instrumento_id', 'version', 'idioma', 'estado', 'publicada_en', 'notas_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['publicada_en' => 'datetime'];
    }

    /** @return BelongsTo<Instrumento, $this> */
    public function instrumento(): BelongsTo
    {
        return $this->belongsTo(Instrumento::class);
    }

    /** @return HasMany<Escala, $this> */
    public function escalas(): HasMany
    {
        return $this->hasMany(Escala::class, 'version_instrumento_id')->orderBy('orden');
    }

    /** @return HasMany<Bloque, $this> */
    public function bloques(): HasMany
    {
        return $this->hasMany(Bloque::class, 'version_instrumento_id')->orderBy('orden');
    }

    /** @return HasMany<Reactivo, $this> */
    public function reactivos(): HasMany
    {
        return $this->hasMany(Reactivo::class, 'version_instrumento_id')->orderBy('orden');
    }

    /** @return HasMany<ClaveCalificacion, $this> */
    public function claves(): HasMany
    {
        return $this->hasMany(ClaveCalificacion::class, 'version_instrumento_id');
    }

    /** @return HasMany<Baremo, $this> */
    public function baremos(): HasMany
    {
        return $this->hasMany(Baremo::class, 'version_instrumento_id');
    }

    /** @return HasMany<ReglaInterpretacion, $this> */
    public function reglasInterpretacion(): HasMany
    {
        return $this->hasMany(ReglaInterpretacion::class, 'version_instrumento_id');
    }

    public function estaPublicada(): bool
    {
        return $this->estado === self::PUBLICADA;
    }

    /**
     * ¿Se puede seguir escribiendo su contenido?
     *
     * Sólo en borrador. Una versión retirada tampoco admite escritura: se
     * retiró justamente para congelarla.
     */
    public function admiteEdicionDeContenido(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    public function etiqueta(): string
    {
        return sprintf('%s (%s)', $this->version, $this->idioma);
    }

    /**
     * @param  Builder<VersionInstrumento>  $consulta
     * @return Builder<VersionInstrumento>
     */
    public function scopePublicadas(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::PUBLICADA);
    }
}
