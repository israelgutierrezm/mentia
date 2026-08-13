<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\Bloque;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El cronómetro. Vive AQUÍ, en el servidor (Doc 02 §7).
 *
 * El tiempo restante se calcula siempre desde `iniciado_en` más la duración
 * declarada del bloque. El cliente sólo lo muestra: si llevara la cuenta,
 * cambiar la hora del sistema o abrir la consola bastaría para tener el doble
 * de tiempo en una prueba cronometrada — y en una prueba de velocidad el
 * tiempo ES el constructo que se mide.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property int $bloque_id
 * @property Carbon|null $iniciado_en
 * @property Carbon|null $finalizado_en
 * @property int $tiempo_consumido_seg
 * @property string $estado
 */
class AplicacionBloque extends Modelo
{
    protected $table = 'aplicacion_bloques';

    protected $fillable = [
        'aplicacion_id', 'bloque_id', 'iniciado_en', 'finalizado_en',
        'tiempo_consumido_seg', 'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'iniciado_en' => 'datetime',
            'finalizado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }

    /** @return BelongsTo<Bloque, $this> */
    public function bloque(): BelongsTo
    {
        return $this->belongsTo(Bloque::class);
    }

    /**
     * Segundos que quedan. NULL si el bloque no tiene cronómetro.
     *
     * Nunca negativo: un "quedan −40 segundos" en pantalla no significa nada
     * para quien está contestando.
     */
    public function restanteSeg(?Carbon $al = null): ?int
    {
        $limite = $this->bloque->tiempo_limite_seg;

        if ($limite === null) {
            return null;
        }

        if ($this->iniciado_en === null) {
            return $limite;
        }

        $transcurrido = $this->tiempo_consumido_seg
            + (int) $this->iniciado_en->diffInSeconds($al ?? Carbon::now());

        return max(0, $limite - $transcurrido);
    }

    /**
     * ¿Se acabó el tiempo?
     *
     * Un bloque sin cronómetro nunca expira, y uno que no ha empezado tampoco.
     */
    public function expirado(?Carbon $al = null): bool
    {
        if ($this->bloque->tiempo_limite_seg === null || $this->iniciado_en === null) {
            return false;
        }

        return $this->restanteSeg($al) === 0;
    }

    public function estaEnCurso(): bool
    {
        return $this->estado === 'en_curso';
    }
}
