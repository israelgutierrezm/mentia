<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Modelos;

use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién se entera de qué alertas, por qué canal.
 *
 * Se configura por ROL y no por persona: quien atiende las críticas es la
 * psicóloga de guardia, y las personas entran y salen de ese rol sin que nadie
 * tenga que actualizar una lista. Una lista de correos se queda apuntando a
 * quien renunció hace dos años, y esa es exactamente la alerta que nadie
 * atiende.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property string $tipo
 * @property string $severidad
 * @property int $rol_id
 * @property string $canal
 * @property bool $activo
 */
class AlertaDestinatario extends Modelo
{
    use PerteneceAOrganizacion;

    protected $table = 'alerta_destinatarios';

    protected $fillable = [
        'organizacion_id', 'tipo', 'severidad', 'rol_id', 'canal', 'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * @param  Builder<AlertaDestinatario>  $consulta
     * @return Builder<AlertaDestinatario>
     */
    public function scopePara(Builder $consulta, string $tipo, string $severidad): Builder
    {
        return $consulta
            ->where('activo', true)
            ->where('tipo', $tipo)
            ->where('severidad', $severidad);
    }
}
