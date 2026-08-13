<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Modelos;

use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una alerta. Las críticas nacen de un reactivo centinela, de forma síncrona,
 * con la aplicación todavía en curso.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int|null $persona_id
 * @property int|null $aplicacion_id
 * @property string $tipo
 * @property string $severidad
 * @property int|null $reactivo_id
 * @property string $mensaje
 * @property string $estado
 * @property int|null $atendida_por
 * @property Carbon|null $atendida_en
 * @property string|null $resolucion
 * @property Carbon $creada_en
 */
class Alerta extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'alertas';

    protected $fillable = [
        'organizacion_id', 'persona_id', 'aplicacion_id', 'tipo', 'severidad',
        'reactivo_id', 'mensaje', 'estado', 'atendida_por', 'atendida_en',
        'resolucion', 'creada_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'atendida_en' => 'datetime',
            'creada_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
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

    /** @return BelongsTo<Persona, $this> */
    public function atendidaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'atendida_por');
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, ['nueva', 'notificada'], true);
    }

    /**
     * Cerrar EXIGE resolución (Doc 06 §5).
     *
     * Una alerta que se puede cerrar en blanco es una alerta que se cierra
     * para limpiar la bandeja, y entonces el registro de qué se hizo ante un
     * riesgo deja de existir justo cuando alguien lo va a pedir.
     */
    public function cerrar(Persona $quien, string $resolucion): self
    {
        if (trim($resolucion) === '') {
            throw new LogicException(
                'Cerrar una alerta exige documentar qué se hizo. Doc 06 §5.'
            );
        }

        $this->update([
            'estado' => 'cerrada',
            'atendida_por' => $quien->id,
            'atendida_en' => Carbon::now(),
            'resolucion' => trim($resolucion),
        ]);

        return $this;
    }

    /**
     * @param  Builder<Alerta>  $consulta
     * @return Builder<Alerta>
     */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', ['nueva', 'notificada']);
    }
}
