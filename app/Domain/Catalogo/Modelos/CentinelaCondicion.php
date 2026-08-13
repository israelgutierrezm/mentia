<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuándo un reactivo centinela dispara.
 *
 * Dos formas, y las dos hacen falta: por OPCIÓN concreta (el screener C-SSRS
 * marca rutas específicas) o por COMPARACIÓN de valor (el reactivo 9 del
 * PHQ-9 dispara con cualquier valor mayor que cero).
 *
 * @property int $id
 * @property int $reactivo_id
 * @property int|null $opcion_id
 * @property string|null $operador
 * @property string|null $valor
 * @property string $severidad
 * @property string $mensaje
 */
class CentinelaCondicion extends Modelo
{
    protected $table = 'centinela_condiciones';

    protected $fillable = [
        'reactivo_id', 'opcion_id', 'operador', 'valor', 'severidad', 'mensaje',
    ];

    /** @return BelongsTo<Reactivo, $this> */
    public function reactivo(): BelongsTo
    {
        return $this->belongsTo(Reactivo::class);
    }

    /**
     * ¿Esta respuesta la dispara?
     *
     * @param  int|null  $opcionId  Opción marcada, si la hubo.
     * @param  float|null  $valor  Valor numérico, si lo hubo.
     */
    public function disparaCon(?int $opcionId, ?float $valor): bool
    {
        if ($this->opcion_id !== null) {
            return $opcionId === $this->opcion_id;
        }

        if ($this->operador === null || $this->valor === null || $valor === null) {
            return false;
        }

        $umbral = (float) $this->valor;

        return match ($this->operador) {
            '>' => $valor > $umbral,
            '>=' => $valor >= $umbral,
            '<' => $valor < $umbral,
            '<=' => $valor <= $umbral,
            '=', '==' => abs($valor - $umbral) < 0.0001,
            '!=' => abs($valor - $umbral) >= 0.0001,

            /*
             * Un operador desconocido NO dispara. Es la decisión incómoda: se
             * elige el falso negativo sobre el falso positivo porque una
             * alerta crítica falsa que se repite entrena al equipo a
             * ignorarlas, y entonces la verdadera tampoco se atiende. Un
             * operador inválido es un error de catálogo que hay que corregir,
             * y por eso el importador lo valida.
             */
            default => false,
        };
    }
}
