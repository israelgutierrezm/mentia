<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un instrumento del catálogo.
 *
 * NO lleva el trait de tenant aunque tenga `organizacion_id`: con NULL es un
 * instrumento GLOBAL del sistema y el global scope lo escondería, dejando a
 * todos los tenants sin catálogo. El acotamiento va en `scopeVisiblesPara()`,
 * que suma los globales a los propios de la organización.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property string $clave
 * @property string $nombre
 * @property string|null $nombre_corto
 * @property int|null $subcategoria_id
 * @property int $dominio_id
 * @property string $estatus_licencia
 * @property string $contenido_incluido
 * @property int $nivel_sensibilidad_id
 * @property string $modo_calificacion
 * @property string $quien_responde
 * @property int|null $edad_min_meses
 * @property int|null $edad_max_meses
 * @property int|null $duracion_estimada_min
 * @property bool $requiere_supervision
 */
class Instrumento extends Modelo implements TieneSensibilidad
{
    public const DOMINIO_PUBLICO = 'dominio_publico';

    public const REQUIERE_LICENCIA = 'requiere_licencia_tenant';

    public const SOLO_CAPTURA = 'solo_captura';

    protected $table = 'instrumentos';

    protected $fillable = [
        'organizacion_id',
        'clave',
        'nombre',
        'nombre_corto',
        'subcategoria_id',
        'dominio_id',
        'estatus_licencia',
        'contenido_incluido',
        'nivel_sensibilidad_id',
        'modo_calificacion',
        'quien_responde',
        'edad_min_meses',
        'edad_max_meses',
        'duracion_estimada_min',
        'requiere_supervision',
        'autor',
        'anio',
        'poblacion_norma',
        'referencia_bibliografica',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requiere_supervision' => 'boolean'];
    }

    /** @return BelongsTo<Dominio, $this> */
    public function dominio(): BelongsTo
    {
        return $this->belongsTo(Dominio::class);
    }

    /** @return BelongsTo<CategoriaInstrumento, $this> */
    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaInstrumento::class, 'subcategoria_id');
    }

    /** @return BelongsTo<NivelSensibilidad, $this> */
    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelSensibilidad::class, 'nivel_sensibilidad_id');
    }

    /** @return HasMany<VersionInstrumento, $this> */
    public function versiones(): HasMany
    {
        return $this->hasMany(VersionInstrumento::class);
    }

    public function nivelSensibilidad(): int
    {
        $nivel = $this->getRelationValue('nivel');

        return $nivel instanceof NivelSensibilidad ? $nivel->nivel : 4;
    }

    /**
     * ¿Se puede aplicar en línea?
     *
     * `solo_captura` significa que la editorial lo prohíbe (WISC, ADOS-2,
     * MMPI): existe en el catálogo para poder registrar sus resultados, no
     * para administrarlo desde aquí (Doc 01 §6).
     */
    public function seAplicaEnLinea(): bool
    {
        return $this->estatus_licencia !== self::SOLO_CAPTURA;
    }

    /**
     * ¿El tenant tiene que declarar licencia y capturar el contenido?
     */
    public function exigeLicenciaDelTenant(): bool
    {
        return $this->estatus_licencia === self::REQUIERE_LICENCIA;
    }

    /**
     * Los GLOBALES más los propios de esa organización.
     *
     * @param  Builder<Instrumento>  $consulta
     * @return Builder<Instrumento>
     */
    public function scopeVisiblesPara(Builder $consulta, ?int $organizacionId): Builder
    {
        return $consulta->where(function (Builder $sub) use ($organizacionId): void {
            $sub->whereNull('organizacion_id');

            if ($organizacionId !== null) {
                $sub->orWhere('organizacion_id', $organizacionId);
            }
        });
    }
}
