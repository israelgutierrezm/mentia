<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Servicios;

use App\Domain\Accesos\Servicios\ClonadorPlantillasRol;
use App\Domain\Organizaciones\Modelos\Organizacion;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta un tenant.
 *
 * El alta y la clonación de sus roles van en UNA transacción: una organización
 * que nace sin roles no tiene forma de que nadie entre a arreglarla —ni
 * siquiera para asignarse un rol, porque asignar roles exige un rol— y sólo se
 * repara desde la consola.
 */
class CreadorOrganizacion
{
    public function __construct(private readonly ClonadorPlantillasRol $clonador) {}

    public function crear(
        string $nombre,
        int $tipoOrganizacionId,
        ?string $rfc = null,
        string $zonaHoraria = 'America/Mexico_City',
    ): Organizacion {
        return DB::transaction(function () use ($nombre, $tipoOrganizacionId, $rfc, $zonaHoraria): Organizacion {
            $organizacion = Organizacion::query()->create([
                'nombre' => $nombre,
                'tipo_organizacion_id' => $tipoOrganizacionId,
                'rfc' => $rfc,
                'estado' => 'activa',
                'zona_horaria' => $zonaHoraria,
            ]);

            $this->clonador->clonarPara($organizacion);

            return $organizacion;
        });
    }
}
