<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Registro append-only de decisiones de acceso.
 *
 * NO hereda de App\Soporte\BaseDatos\Modelo: no tiene creado_en/actualizado_en.
 * Una fila de bitácora se escribe una vez y nunca cambia, así que una columna
 * `actualizado_en` sería una promesa falsa; el instante va en `registrado_en`
 * con precisión de milésimas.
 *
 * El bloqueo de UPDATE y DELETE vive aquí Y en los privilegios del usuario
 * MySQL de la aplicación (Doc 06 §4). Las dos capas: esta se esquiva con un
 * `DB::table('bitacora')->delete()`, y la de la base no.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property int|null $actor_persona_id
 * @property string $accion
 * @property string $recurso_tipo
 * @property int|null $recurso_id
 * @property int|null $persona_afectada_id
 * @property int|null $proposito_id
 * @property string $resultado
 * @property string|null $motivo
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $registrado_en
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    public $timestamps = false;

    protected $fillable = [
        'organizacion_id',
        'actor_persona_id',
        'accion',
        'recurso_tipo',
        'recurso_id',
        'persona_afectada_id',
        'proposito_id',
        'resultado',
        'motivo',
        'ip',
        'user_agent',
        'registrado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['registrado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'La bitácora es append-only: una decisión de acceso registrada no se corrige, '
                .'se registra otra. Doc 06 §4.'
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'La bitácora es append-only: borrar el rastro de un acceso es justo lo que la '
                .'LFPDPPP obliga a conservar. Doc 06 §4.'
            );
        });
    }
}
