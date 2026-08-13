<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Modelos;

use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un punto de la línea de tiempo psicométrica de una persona.
 *
 * ES LA TABLA DE LA IDEA RECTORA: la persona es la entidad permanente y las
 * evaluaciones son eventos que se acumulan en su expediente. Cuelga de
 * `persona_id`, no de la aplicación, y por eso la serie sobrevive a que la
 * organización que aplicó la prueba desaparezca.
 *
 * Deliberadamente SIN el global scope de tenant: la serie de una persona es
 * suya, y filtrarla por la organización activa mostraría un expediente
 * incompleto sin decir que lo está. Quién puede verla lo decide AccesoService,
 * que es donde se decide todo lo demás.
 *
 * @property int $id
 * @property int $persona_id
 * @property int $dominio_id
 * @property string $constructo
 * @property int $version_instrumento_id
 * @property int $aplicacion_id
 * @property int|null $organizacion_id_contexto
 * @property Carbon $fecha
 * @property string $tipo_norma
 * @property float $valor
 * @property string|null $bandera
 */
class ResultadoNormalizado extends Modelo
{
    protected $table = 'resultados_normalizados';

    protected $fillable = [
        'persona_id', 'dominio_id', 'constructo', 'version_instrumento_id',
        'aplicacion_id', 'organizacion_id_contexto', 'fecha', 'tipo_norma',
        'valor', 'bandera',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'valor' => 'float'];
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /** @return BelongsTo<Dominio, $this> */
    public function dominio(): BelongsTo
    {
        return $this->belongsTo(Dominio::class);
    }

    /**
     * La serie de un constructo, en orden cronológico.
     *
     * @param  Builder<ResultadoNormalizado>  $consulta
     * @return Builder<ResultadoNormalizado>
     */
    public function scopeSerieDe(Builder $consulta, int $personaId, string $constructo): Builder
    {
        return $consulta
            ->where('persona_id', $personaId)
            ->where('constructo', $constructo)
            ->orderBy('fecha');
    }
}
