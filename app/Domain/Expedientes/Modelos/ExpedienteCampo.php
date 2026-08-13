<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un campo del expediente, descrito como configuración.
 *
 * Agregar un dato al expediente es una FILA aquí, no una columna ni una
 * migración (principio P3).
 *
 * @property int $id
 * @property int $seccion_id
 * @property string $clave
 * @property string $etiqueta
 * @property string $tipo_dato
 * @property int|null $catalogo_opciones_id
 * @property bool $obligatorio
 * @property string $quien_puede_llenar
 * @property int $nivel_sensibilidad_id
 * @property int $orden
 * @property bool $activo
 */
class ExpedienteCampo extends Modelo implements TieneSensibilidad
{
    protected $table = 'expediente_campos';

    protected $fillable = [
        'seccion_id',
        'clave',
        'etiqueta',
        'tipo_dato',
        'catalogo_opciones_id',
        'obligatorio',
        'quien_puede_llenar',
        'nivel_sensibilidad_id',
        'orden',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SeccionExpediente, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(SeccionExpediente::class, 'seccion_id');
    }

    /**
     * @return BelongsTo<NivelSensibilidad, $this>
     */
    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelSensibilidad::class, 'nivel_sensibilidad_id');
    }

    /**
     * @return BelongsTo<CatalogoOpciones, $this>
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(CatalogoOpciones::class, 'catalogo_opciones_id');
    }

    public function nivelSensibilidad(): int
    {
        $nivel = $this->getRelationValue('nivel');

        return $nivel instanceof NivelSensibilidad ? $nivel->nivel : 4;
    }

    /**
     * La columna donde aterriza el valor, según el tipo del campo.
     */
    public function columnaDeValor(): string
    {
        return match ($this->tipo_dato) {
            'numero' => 'valor_numero',
            'fecha' => 'valor_fecha',
            'catalogo' => 'valor_opcion_id',
            'archivo' => 'media_id',
            default => 'valor_texto',
        };
    }

    /**
     * ¿Lo capturado por este rol nace validado o pendiente?
     *
     * Lo que escribe el titular o el tutor entra `pendiente_validacion`: los
     * datos los aporta la persona, pero quien responde de ellos ante la
     * organización es un profesional. Lo que captura un profesional ya nace
     * validado — pedirle que valide lo suyo sería un trámite vacío.
     */
    public function loCapturadoNaceValidado(string $rolDeQuienCaptura): bool
    {
        return in_array($rolDeQuienCaptura, ['profesional', 'admin'], true);
    }
}
