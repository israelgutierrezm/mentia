<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * La orden de evaluación: a quién, qué, cuándo y para qué.
 *
 * @property int $id
 * @property string $folio
 * @property int $organizacion_id
 * @property int|null $version_instrumento_id
 * @property int|null $bateria_id
 * @property int $proposito_id
 * @property string $origen_tipo
 * @property int|null $agrupacion_id
 * @property bool $incluir_nuevos_miembros
 * @property int $asignado_por
 * @property bool $es_discreta
 * @property bool $es_anonima
 * @property Carbon $ventana_inicio
 * @property Carbon $ventana_fin
 * @property int $intentos_permitidos
 * @property string $modo_presentacion
 * @property bool $requiere_consentimiento
 * @property int|null $tipo_consentimiento_id
 * @property string $estado
 */
class Asignacion extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'asignaciones';

    protected $fillable = [
        'folio', 'organizacion_id', 'version_instrumento_id', 'bateria_id',
        'proposito_id', 'origen_tipo', 'agrupacion_id', 'incluir_nuevos_miembros',
        'asignado_por', 'es_discreta', 'es_anonima', 'ventana_inicio', 'ventana_fin',
        'intentos_permitidos', 'modo_presentacion', 'requiere_consentimiento',
        'tipo_consentimiento_id', 'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'incluir_nuevos_miembros' => 'boolean',
            'es_discreta' => 'boolean',
            'es_anonima' => 'boolean',
            'requiere_consentimiento' => 'boolean',
            'ventana_inicio' => 'datetime',
            'ventana_fin' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        // El folio, no el id: es lo que se dicta por teléfono y lo que viaja
        // en la API (Doc 07 §4).
        return 'folio';
    }

    /** @return HasMany<AsignacionDestinatario, $this> */
    public function destinatarios(): HasMany
    {
        return $this->hasMany(AsignacionDestinatario::class);
    }

    /** @return BelongsTo<Proposito, $this> */
    public function proposito(): BelongsTo
    {
        return $this->belongsTo(Proposito::class);
    }

    /** @return BelongsTo<Bateria, $this> */
    public function bateria(): BelongsTo
    {
        return $this->belongsTo(Bateria::class);
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return BelongsTo<Agrupacion, $this> */
    public function agrupacion(): BelongsTo
    {
        return $this->belongsTo(Agrupacion::class);
    }

    /** @return BelongsTo<Persona, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'asignado_por');
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activa';
    }

    /**
     * ¿Se puede contestar AHORA?
     *
     * Estado activa Y dentro de la ventana. Las dos: cerrar una asignación no
     * es lo mismo que que se venza, y un token dentro de una asignación
     * cancelada no debe abrir nada.
     */
    public function ventanaAbierta(?Carbon $al = null): bool
    {
        if (! $this->estaActiva()) {
            return false;
        }

        $momento = $al ?? Carbon::now();

        return $this->ventana_inicio->lessThanOrEqualTo($momento)
            && $this->ventana_fin->greaterThanOrEqualTo($momento);
    }

    /**
     * Una asignación DINÁMICA sigue admitiendo miembros nuevos de su
     * agrupación mientras su ventana esté abierta.
     */
    public function esDinamica(): bool
    {
        return $this->origen_tipo === 'agrupacion'
            && $this->incluir_nuevos_miembros
            && $this->agrupacion_id !== null;
    }

    /**
     * Las asignaciones que un actor PUEDE ver.
     *
     * Una discreta sólo la ve quien la creó. Es el caso clínico: que el resto
     * del área sepa que existe una evaluación asignada a alguien ya es una
     * filtración, aunque nadie vea el resultado.
     *
     * El nivel de sensibilidad del actor abre la excepción del Doc 06 §1: un
     * rol de nivel 4 sí las ve, porque es quien tiene que poder atenderlas.
     *
     * @param  Builder<Asignacion>  $consulta
     * @return Builder<Asignacion>
     */
    public function scopeVisiblesPara(Builder $consulta, Persona $actor, int $nivelDelActor): Builder
    {
        if ($nivelDelActor >= 4) {
            return $consulta;
        }

        return $consulta->where(function (Builder $sub) use ($actor): void {
            $sub->where('es_discreta', false)
                ->orWhere('asignado_por', $actor->id);
        });
    }
}
