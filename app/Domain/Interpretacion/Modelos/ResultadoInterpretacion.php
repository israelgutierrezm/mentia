<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Catalogo\Modelos\PerfilTipo;
use App\Domain\Catalogo\Modelos\ReglaInterpretacion;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un texto de interpretación ya resuelto para una audiencia.
 *
 * Se guarda el texto RESUELTO, no la plantilla. La regla puede editarse mañana;
 * lo que se le dijo a esta persona ese día no cambia.
 *
 * @property int $id
 * @property int $aplicacion_id
 * @property int|null $regla_interpretacion_id
 * @property int|null $perfil_tipo_id
 * @property string $audiencia
 * @property string $texto_resuelto
 * @property string|null $bandera
 * @property int $orden
 */
class ResultadoInterpretacion extends Modelo
{
    protected $table = 'resultados_interpretacion';

    protected $fillable = [
        'aplicacion_id', 'regla_interpretacion_id', 'perfil_tipo_id',
        'audiencia', 'texto_resuelto', 'bandera', 'orden',
    ];

    /**
     * El texto resuelto va CIFRADO (Doc 06 §4, "resultados sensibilidad 3–4").
     *
     * «Puntaje compatible con sintomatología depresiva moderada» es material
     * clínico, y a diferencia del puntaje —que no dice nada sin su baremo— este
     * texto se entiende leyéndolo. Quien abra un respaldo de la base no debe
     * poder leer el tamizaje de nadie.
     *
     * Se cifran TODOS, no sólo los de instrumentos sensibles: decidirlo por
     * instrumento obligaría a consultar su nivel en cada lectura, y el día que
     * alguien reclasificara un instrumento los textos viejos quedarían
     * ilegibles o los nuevos en claro sin que nada protestara.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['texto_resuelto' => 'encrypted'];
    }

    /** @return BelongsTo<Aplicacion, $this> */
    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(Aplicacion::class);
    }

    /** @return BelongsTo<ReglaInterpretacion, $this> */
    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaInterpretacion::class, 'regla_interpretacion_id');
    }

    /** @return BelongsTo<PerfilTipo, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilTipo::class, 'perfil_tipo_id');
    }

    /**
     * @param  Builder<ResultadoInterpretacion>  $consulta
     * @return Builder<ResultadoInterpretacion>
     */
    public function scopeParaAudiencia(Builder $consulta, string $audiencia): Builder
    {
        return $consulta->where('audiencia', $audiencia)->orderBy('orden');
    }
}
