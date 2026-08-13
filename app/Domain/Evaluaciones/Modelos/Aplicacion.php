<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una instancia de respuesta de UN instrumento por UNA persona.
 *
 * Hereda su sensibilidad del instrumento: un PHQ-9 contestado es contenido
 * clínico, y quien lo mire tiene que alcanzar ese nivel.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organizacion_id
 * @property int $asignacion_destinatario_id
 * @property int $version_instrumento_id
 * @property int|null $persona_id
 * @property int|null $quien_respondio_persona_id
 * @property string $modalidad
 * @property string $modo_presentacion
 * @property string $estado
 * @property Carbon $iniciada_en
 * @property Carbon|null $finalizada_en
 * @property int $tiempo_efectivo_seg
 * @property int|null $edad_meses_al_aplicar
 * @property string $validez
 * @property int $numero_intento
 */
class Aplicacion extends Modelo implements TieneSensibilidad
{
    use PerteneceAOrganizacion;

    protected $table = 'aplicaciones';

    protected $fillable = [
        'uuid', 'organizacion_id', 'asignacion_destinatario_id', 'version_instrumento_id',
        'persona_id', 'quien_respondio_persona_id', 'modalidad', 'modo_presentacion',
        'estado', 'iniciada_en', 'finalizada_en', 'tiempo_efectivo_seg', 'dispositivo',
        'ip', 'edad_meses_al_aplicar', 'validez', 'motivo_invalidez', 'numero_intento',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $aplicacion): void {
            if ($aplicacion->uuid === null) {
                $aplicacion->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return BelongsTo<AsignacionDestinatario, $this> */
    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(AsignacionDestinatario::class, 'asignacion_destinatario_id');
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /** @return HasMany<AplicacionBloque, $this> */
    public function bloques(): HasMany
    {
        return $this->hasMany(AplicacionBloque::class);
    }

    /** @return HasMany<Respuesta, $this> */
    public function respuestas(): HasMany
    {
        return $this->hasMany(Respuesta::class);
    }

    public function esAnonima(): bool
    {
        return $this->persona_id === null;
    }

    public function admiteRespuestas(): bool
    {
        return in_array($this->estado, ['iniciada', 'en_pausa'], true);
    }

    public function estaCompletada(): bool
    {
        return $this->estado === 'completada';
    }

    /**
     * La sensibilidad la hereda del instrumento. Sin declaración, 4: una
     * aplicación cuyo instrumento no se pudo cargar es lo último que conviene
     * mostrar por omisión.
     */
    public function nivelSensibilidad(): int
    {
        $version = $this->getRelationValue('version');

        if (! $version instanceof VersionInstrumento) {
            return 4;
        }

        return $version->instrumento->nivelSensibilidad();
    }
}
