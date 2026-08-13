<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un reactivo. Es el contenido más protegido del sistema.
 *
 * `organizacion_id_contenido` decide de quién es: NULL = global, con valor =
 * privado de esa organización, capturado bajo SU licencia. El scope
 * `deContenidoVisiblePara()` es lo que impide que el contenido licenciado de
 * un tenant llegue a otro — y eso no es una preferencia: es la cadena de
 * responsabilidad ante la editorial (Doc 06 §3).
 *
 * @property int $id
 * @property int $version_instrumento_id
 * @property int $bloque_id
 * @property int $tipo_reactivo_id
 * @property string $codigo
 * @property string $enunciado
 * @property int|null $media_id
 * @property int|null $organizacion_id_contenido
 * @property bool $es_inverso
 * @property bool $es_centinela
 * @property bool $obligatorio
 * @property int $orden
 * @property int|null $tiempo_limite_seg
 */
class Reactivo extends Modelo
{
    protected $table = 'reactivos';

    protected $fillable = [
        'version_instrumento_id',
        'bloque_id',
        'tipo_reactivo_id',
        'codigo',
        'enunciado',
        'media_id',
        'organizacion_id_contenido',
        'es_inverso',
        'es_centinela',
        'obligatorio',
        'orden',
        'tiempo_limite_seg',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'es_inverso' => 'boolean',
            'es_centinela' => 'boolean',
            'obligatorio' => 'boolean',
        ];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return BelongsTo<Bloque, $this> */
    public function bloque(): BelongsTo
    {
        return $this->belongsTo(Bloque::class);
    }

    /** @return BelongsTo<TipoReactivo, $this> */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoReactivo::class, 'tipo_reactivo_id');
    }

    /** @return HasMany<OpcionReactivo, $this> */
    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionReactivo::class)->orderBy('orden');
    }

    /** @return HasMany<ClaveCalificacion, $this> */
    public function claves(): HasMany
    {
        return $this->hasMany(ClaveCalificacion::class);
    }

    public function esContenidoGlobal(): bool
    {
        return $this->organizacion_id_contenido === null;
    }

    /**
     * El contenido GLOBAL más el privado de esa organización. Nunca el de otra.
     *
     * Todo lo que sirva reactivos —el motor de aplicación, el pipeline de
     * calificación, la vista previa del catálogo— pasa por aquí. Es el punto
     * único, igual que AccesoService lo es para los datos de personas.
     *
     * @param  Builder<Reactivo>  $consulta
     * @return Builder<Reactivo>
     */
    public function scopeDeContenidoVisiblePara(Builder $consulta, ?int $organizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($organizacionId): void {
            $sub->whereNull('organizacion_id_contenido');

            if ($organizacionId !== null) {
                $sub->orWhere('organizacion_id_contenido', $organizacionId);
            }
        });
    }
}
