<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Modelos;

use App\Soporte\BaseDatos\Modelo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * El texto exacto que alguien aceptó, congelado en una versión.
 *
 * INMUTABLE tras publicarse (principio P4). Un consentimiento apunta a la
 * versión, y el hash comprueba que la versión no se tocó: es lo que permite
 * demostrar años después que el texto aceptado es éste y no uno editado
 * después de la firma.
 *
 * NO lleva el trait de tenant aunque tenga `organizacion_id`: con NULL es un
 * texto de plataforma visible para todos, y el global scope lo escondería
 * —dejando a los tenants sin aviso de privacidad—.
 *
 * @property int $id
 * @property int|null $organizacion_id
 * @property int $tipo_consentimiento_id
 * @property int $version
 * @property string $titulo
 * @property string $cuerpo
 * @property string $hash_sha256
 * @property Carbon $vigente_desde
 */
class TextoConsentimiento extends Modelo
{
    protected $table = 'textos_consentimiento';

    protected $fillable = [
        'organizacion_id',
        'tipo_consentimiento_id',
        'version',
        'titulo',
        'cuerpo',
        'hash_sha256',
        'vigente_desde',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['vigente_desde' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $texto): void {
            $texto->hash_sha256 = self::hashDe($texto->cuerpo);
        });

        static::updating(function (self $texto): void {
            /*
             * El cuerpo y la versión no se editan. Si se pudiera, un tenant
             * cambiaría el texto después de que mil personas lo firmaron y
             * todos esos consentimientos pasarían a amparar algo que nadie
             * aceptó.
             */
            if ($texto->isDirty(['cuerpo', 'version', 'tipo_consentimiento_id', 'hash_sha256'])) {
                throw new LogicException(
                    'Un texto de consentimiento publicado no se edita: se publica una versión '
                    .'nueva. Doc 01 P4.'
                );
            }
        });
    }

    public static function hashDe(string $cuerpo): string
    {
        return hash('sha256', $cuerpo);
    }

    /**
     * ¿El cuerpo guardado sigue siendo el que se firmó?
     *
     * Se comprueba al mostrar un consentimiento otorgado: si alguien tocó la
     * fila por fuera de la aplicación —un UPDATE a mano en la base—, el hash
     * deja de cuadrar y el consentimiento no se puede seguir presentando como
     * prueba.
     */
    public function integroSegunHash(): bool
    {
        return hash_equals($this->hash_sha256, self::hashDe($this->cuerpo));
    }

    /**
     * @return BelongsTo<TipoConsentimiento, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoConsentimiento::class, 'tipo_consentimiento_id');
    }
}
