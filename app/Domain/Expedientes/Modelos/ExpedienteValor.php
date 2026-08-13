<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una respuesta de campo, en una versión.
 *
 * Corregir un dato NO lo pisa: agrega una versión. El vigente es la mayor
 * versión validada. Es lo que hace posible la rectificación ARCO sin destruir
 * el dato anterior y lo que deja ver que algo cambió, cuándo y quién lo validó.
 *
 * NO lleva el trait de tenant. `organizacion_id_contexto` dice DÓNDE se
 * capturó, no de quién es: el valor pertenece al expediente, que es global.
 * Acotarlo por tenant escondería del expediente de vida justo lo que otra
 * organización capturó.
 *
 * @property int $id
 * @property int $expediente_id
 * @property int $campo_id
 * @property int|null $organizacion_id_contexto
 * @property string|null $valor_texto
 * @property string|null $valor_numero
 * @property Carbon|null $valor_fecha
 * @property int|null $valor_opcion_id
 * @property int|null $media_id
 * @property int $capturado_por
 * @property string $estado
 * @property int|null $validado_por
 * @property int $version
 */
class ExpedienteValor extends Modelo implements TieneSensibilidad
{
    protected $table = 'expediente_valores';

    protected $fillable = [
        'expediente_id',
        'campo_id',
        'organizacion_id_contexto',
        'valor_texto',
        'valor_numero',
        'valor_fecha',
        'valor_opcion_id',
        'media_id',
        'capturado_por',
        'estado',
        'validado_por',
        'version',
    ];

    /**
     * `valor_texto` va CIFRADO (Doc 06 §4, "campos sensibles de expediente").
     *
     * Se cifra TODO el campo de texto, no sólo el de secciones sensibles. Es un
     * superconjunto de lo que el documento pide, y a propósito: cifrar según la
     * sensibilidad de la sección exigiría consultar el catálogo en cada lectura
     * de atributo —una consulta por celda de una pantalla de expediente— y la
     * primera vez que alguien moviera un campo de sección dejaría datos viejos
     * ilegibles o nuevos en claro sin que nada protestara.
     *
     * El precio es que `valor_texto` deja de ser buscable por SQL. Nadie lo
     * busca —los valores se leen por campo, no por contenido— y una búsqueda de
     * texto libre sobre domicilios y antecedentes clínicos sería de todas
     * formas un problema de privacidad, no una funcionalidad.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_fecha' => 'date',
            'valor_texto' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<ExpedienteCampo, $this>
     */
    public function campo(): BelongsTo
    {
        return $this->belongsTo(ExpedienteCampo::class, 'campo_id');
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'capturado_por');
    }

    /**
     * @return BelongsTo<OpcionCatalogo, $this>
     */
    public function opcion(): BelongsTo
    {
        return $this->belongsTo(OpcionCatalogo::class, 'valor_opcion_id');
    }

    /**
     * La sensibilidad la hereda del CAMPO. Un valor no puede ser menos
     * sensible que el campo al que responde.
     */
    public function nivelSensibilidad(): int
    {
        $campo = $this->getRelationValue('campo');

        return $campo instanceof ExpedienteCampo ? $campo->nivelSensibilidad() : 4;
    }

    /**
     * El contenido, sea cual sea la columna en la que aterrizó.
     */
    public function contenido(): string|int|float|null
    {
        return match (true) {
            $this->valor_texto !== null => $this->valor_texto,
            $this->valor_numero !== null => (float) $this->valor_numero,
            $this->valor_fecha !== null => $this->valor_fecha->toDateString(),
            $this->valor_opcion_id !== null => $this->getRelationValue('opcion')?->etiqueta,
            $this->media_id !== null => $this->media_id,
            default => null,
        };
    }

    /**
     * @param  Builder<ExpedienteValor>  $consulta
     * @return Builder<ExpedienteValor>
     */
    public function scopeValidados(Builder $consulta): Builder
    {
        return $consulta->where('estado', 'validado');
    }
}
