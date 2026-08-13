<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una persona concreta dentro de una asignación, con su token.
 *
 * El token se guarda HASHEADO. Quien lea la base —un respaldo, un volcado, una
 * consulta de soporte— no debe poder entrar a contestar en nombre de nadie. El
 * token en claro sólo existe el instante en que se manda por correo.
 *
 * @property int $id
 * @property int $asignacion_id
 * @property int $persona_id
 * @property int|null $quien_responde_persona_id
 * @property string $estado
 * @property string|null $token
 * @property Carbon|null $token_expira_en
 * @property Carbon|null $token_usado_en
 * @property string|null $motivo_exencion
 * @property Carbon|null $notificada_en
 * @property int $recordatorios_enviados
 */
class AsignacionDestinatario extends Modelo
{
    protected $table = 'asignacion_destinatarios';

    protected $fillable = [
        'asignacion_id', 'persona_id', 'quien_responde_persona_id', 'estado',
        'token', 'token_expira_en', 'token_usado_en', 'motivo_exencion',
        'notificada_en', 'recordatorios_enviados',
    ];

    /**
     * El token nunca se serializa. Ni en un API Resource por descuido, ni en
     * un `dd()` durante una sesión de soporte.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_expira_en' => 'datetime',
            'token_usado_en' => 'datetime',
            'notificada_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asignacion, $this> */
    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class);
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * Quién contesta, si no es la propia persona.
     *
     * La madre responde el M-CHAT SOBRE su hijo: `persona` es el niño,
     * `quienResponde` es la madre.
     *
     * @return BelongsTo<Persona, $this>
     */
    public function quienResponde(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'quien_responde_persona_id');
    }

    public function estaExenta(): bool
    {
        return $this->estado === 'exenta';
    }

    public function yaContesto(): bool
    {
        return $this->estado === 'completada';
    }

    /**
     * ¿El token sirve todavía?
     *
     * Tres condiciones y las tres importan:
     *  - no se ha consumido —y "consumido" significa que ya no queda nada que
     *    contestar, no que se haya canjeado una vez—,
     *  - no ha expirado por su propia fecha,
     *  - y la ventana de la asignación sigue abierta.
     *
     * La tercera es la que se olvida: cerrar una asignación tiene que invalidar
     * sus tokens al instante, sin esperar a que cada uno venza por su cuenta.
     *
     * SOBRE EL UN SOLO USO. Un token canjeado sigue sirviendo MIENTRAS el
     * destinatario está `en_curso`. La regla estricta —muerto al primer canje—
     * es correcta contra la liga reenviada, y equivocada contra el caso mucho
     * más común: quien cierra la pestaña a la mitad y vuelve a picar la liga del
     * correo. Con ella, cada cierre accidental abandona un instrumento a medias
     * y nadie sabe por qué. Lo que el token no puede hacer nunca es abrir un
     * intento NUEVO: en cuanto la aplicación se completa, el estado pasa a
     * `completada` y esto devuelve false.
     */
    public function tokenVigente(?Carbon $al = null): bool
    {
        if ($this->token === null) {
            return false;
        }

        if ($this->token_usado_en !== null && $this->estado !== 'en_curso') {
            return false;
        }

        $momento = $al ?? Carbon::now();

        if ($this->token_expira_en !== null && $this->token_expira_en->lessThan($momento)) {
            return false;
        }

        return $this->asignacion->ventanaAbierta($momento);
    }

    /**
     * @param  Builder<AsignacionDestinatario>  $consulta
     * @return Builder<AsignacionDestinatario>
     */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', [
            'pendiente', 'consentimiento_pendiente', 'notificada', 'en_curso',
        ]);
    }
}
