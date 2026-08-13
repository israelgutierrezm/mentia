<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Modelos;

use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La persona decide qué parte de su historial ve otra organización.
 *
 * Es el mecanismo del expediente de vida cross-tenant (Doc 02 §3): estar
 * vinculada a un tenant NO le da a ese tenant lo que la persona generó en
 * otro. Sólo esto lo abre, y sólo mientras la persona quiera.
 *
 * @property int $id
 * @property int $persona_id
 * @property int $organizacion_destino_id
 * @property int|null $dominio_id
 * @property int $consentimiento_id
 * @property string $alcance
 * @property Carbon|null $vigencia_fin
 * @property Carbon|null $revocado_en
 */
class ComparticionExpediente extends Modelo
{
    protected $table = 'comparticiones_expediente';

    protected $fillable = [
        'persona_id',
        'organizacion_destino_id',
        'dominio_id',
        'consentimiento_id',
        'alcance',
        'vigencia_fin',
        'revocado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigencia_fin' => 'date',
            'revocado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<Organizacion, $this>
     */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_destino_id');
    }

    /**
     * @return BelongsTo<Consentimiento, $this>
     */
    public function consentimiento(): BelongsTo
    {
        return $this->belongsTo(Consentimiento::class);
    }

    /**
     * Vigente = sin revocar, dentro de fecha Y con su consentimiento vigente.
     *
     * La tercera condición es la que importa: revocar el consentimiento de
     * compartición tiene que cerrar la compartición, aunque nadie toque esta
     * fila. Si no, revocar sería un gesto que no hace nada.
     */
    public function estaVigente(?Carbon $al = null): bool
    {
        if ($this->revocado_en !== null) {
            return false;
        }

        $fecha = $al ?? Carbon::now();

        if ($this->vigencia_fin !== null && $this->vigencia_fin->endOfDay()->lessThan($fecha)) {
            return false;
        }

        $consentimiento = $this->getRelationValue('consentimiento');

        return $consentimiento instanceof Consentimiento
            && $consentimiento->estaVigente($fecha);
    }

    /**
     * @param  Builder<ComparticionExpediente>  $consulta
     * @return Builder<ComparticionExpediente>
     */
    public function scopeVigentes(Builder $consulta, ?Carbon $al = null): Builder
    {
        $fecha = $al ?? Carbon::now();

        return $consulta
            ->whereNull('revocado_en')
            ->where(function (Builder $sub) use ($fecha): void {
                $sub->whereNull('vigencia_fin')->orWhereDate('vigencia_fin', '>=', $fecha);
            })
            /*
             * `whereIn` contra el scope y no `whereHas` con una clausura: así
             * la vigencia sigue viviendo en UN solo lugar
             * (Consentimiento::scopeVigentes) en vez de reescribirse aquí, que
             * es como las dos definiciones terminan divergiendo.
             */
            ->whereIn(
                'consentimiento_id',
                Consentimiento::query()->vigentes($fecha)->select('id')
            );
    }
}
