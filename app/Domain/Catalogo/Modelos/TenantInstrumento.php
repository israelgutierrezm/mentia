<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Modelos;

use App\Domain\Personas\Modelos\Persona;
use App\Soporte\BaseDatos\Modelo;
use App\Soporte\Multitenencia\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La habilitación de un instrumento para una organización.
 *
 * Que exista en el catálogo no significa que se pueda aplicar: esta fila es la
 * puerta. Sí lleva el trait de tenant —es dato de tenant, no catálogo global—.
 *
 * @property int $id
 * @property int $organizacion_id
 * @property int $version_instrumento_id
 * @property string $estado
 * @property string $origen_contenido
 * @property string|null $declaracion_licencia_texto
 * @property int|null $declaracion_firmada_por
 * @property Carbon|null $declaracion_firmada_en
 * @property int|null $evidencia_media_id
 * @property Carbon|null $habilitado_en
 */
class TenantInstrumento extends Modelo
{
    use PerteneceAOrganizacion;

    public const DISPONIBLE = 'disponible';

    public const HABILITADO = 'habilitado';

    public const PENDIENTE_CONTENIDO = 'pendiente_contenido';

    public const BLOQUEADO = 'bloqueado';

    protected $table = 'tenant_instrumentos';

    protected $fillable = [
        'organizacion_id',
        'version_instrumento_id',
        'estado',
        'origen_contenido',
        'declaracion_licencia_texto',
        'declaracion_firmada_por',
        'declaracion_firmada_en',
        'evidencia_media_id',
        'habilitado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'declaracion_firmada_en' => 'datetime',
            'habilitado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<VersionInstrumento, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionInstrumento::class, 'version_instrumento_id');
    }

    /** @return BelongsTo<Persona, $this> */
    public function firmante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'declaracion_firmada_por');
    }

    /**
     * ¿Se puede asignar ya?
     *
     * Sólo `habilitado`. `pendiente_contenido` es el estado de un instrumento
     * con licencia declarada al que todavía le faltan reactivos: asignarlo
     * mandaría a alguien a contestar una prueba vacía.
     */
    public function sePuedeAsignar(): bool
    {
        return $this->estado === self::HABILITADO;
    }

    public function tieneDeclaracionFirmada(): bool
    {
        return $this->declaracion_licencia_texto !== null
            && $this->declaracion_firmada_por !== null;
    }
}
