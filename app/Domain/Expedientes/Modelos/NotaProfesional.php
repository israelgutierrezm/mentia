<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Modelos;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota clínica del profesional.
 *
 * Dos cosas que la separan de todo lo demás del expediente:
 *
 * 1. El contenido va CIFRADO a nivel aplicación (Doc 06 §4). Un volcado de la
 *    base o un respaldo robado no debe poder leerse.
 * 2. NUNCA es visible para el titular directamente (Doc 03 §M4). No es
 *    opacidad: una nota clínica en crudo, sin la conversación que la acompaña,
 *    hace daño. Lo que el titular recibe es la interpretación redactada para
 *    su audiencia.
 *
 * Su sensibilidad es SIEMPRE 4, y `visible_para` la acota todavía más: `autor`
 * significa que ni siquiera otro profesional de nivel 4 la ve.
 *
 * @property int $id
 * @property int $expediente_id
 * @property int $organizacion_id
 * @property int $autor_persona_id
 * @property string $contenido
 * @property int $nivel_sensibilidad_id
 * @property string $visible_para
 */
class NotaProfesional extends Modelo implements TieneSensibilidad
{
    use PerteneceAOrganizacion;

    protected $table = 'notas_profesionales';

    protected $fillable = [
        'expediente_id',
        'organizacion_id',
        'autor_persona_id',
        'contenido',
        'nivel_sensibilidad_id',
        'visible_para',
    ];

    protected $hidden = ['contenido'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `encrypted`: Laravel cifra al guardar y descifra al leer con la
        // APP_KEY. Sin la llave, la columna es ruido.
        return ['contenido' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'autor_persona_id');
    }

    public function nivelSensibilidad(): int
    {
        return 4;
    }

    /**
     * Excepción estructural del Doc 06 §1: la nota no se resuelve sólo con la
     * matriz de sensibilidad. Aunque alguien alcance el nivel 4, si la nota es
     * `autor` sólo la ve quien la escribió.
     *
     * Y el titular NUNCA, ni siendo dueño del dato. Es la única parte de su
     * expediente que no le llega en crudo.
     */
    public function laPuedeVer(Persona $actor, int $nivelDelActor): bool
    {
        if ($actor->id === $this->autor_persona_id) {
            return true;
        }

        if ($actor->id === $this->expediente->persona_id) {
            return false;
        }

        return $this->visible_para === 'nivel_4' && $nivelDelActor >= 4;
    }
}
