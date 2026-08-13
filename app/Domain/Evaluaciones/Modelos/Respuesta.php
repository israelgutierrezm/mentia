<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una respuesta a un reactivo.
 *
 * `valor_texto` va CIFRADO: una respuesta abierta puede contener cualquier
 * cosa que la persona haya querido escribir, y en un tamizaje clínico eso
 * incluye lo más delicado de su expediente (Doc 06 §4).
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property int $reactivo_id
 * @property int|null $opcion_id
 * @property string|null $valor_numerico
 * @property string|null $valor_texto
 * @property int|null $media_id
 * @property string|null $rol_ipsativo
 * @property int|null $posicion_ranking
 * @property string $uuid_cliente
 * @property int|null $tiempo_respuesta_ms
 * @property Carbon $respondida_en
 * @property string $origen
 * @property bool $tardia
 */
class Respuesta extends Modelo
{
    protected $table = 'respuestas';

    protected $fillable = [
        'aplicacion_id', 'reactivo_id', 'opcion_id', 'valor_numerico', 'valor_texto',
        'media_id', 'rol_ipsativo', 'posicion_ranking', 'uuid_cliente',
        'tiempo_respuesta_ms', 'respondida_en', 'origen', 'tardia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valor_texto' => 'encrypted',
            'respondida_en' => 'datetime',
            'tardia' => 'boolean',
        ];
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }

    /** @return BelongsTo<Reactivo, $this> */
    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class);
    }

    /** @return BelongsTo<OpcionReactivo, $this> */
    public function opcion(): BelongsTo
    {
        return $this->belongsTo(OpcionReactivo::class, 'opcion_id');
    }

    /**
     * El valor con el que puntúa esta respuesta.
     *
     * El peso lo pone la clave de calificación (Fase 7); esto es sólo lo que
     * la persona marcó.
     */
    public function valor(): float|string|null
    {
        return match (true) {
            $this->valor_numerico !== null => (float) $this->valor_numerico,
            $this->opcion_id !== null => (float) $this->opcion_id,
            $this->valor_texto !== null => $this->valor_texto,
            default => null,
        };
    }
}
