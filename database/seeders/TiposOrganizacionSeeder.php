<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organizaciones\Modelos\TipoAgrupacion;
use App\Domain\Organizaciones\Modelos\TipoOrganizacion;
use Illuminate\Database\Seeder;

/**
 * Tipos de tenant y tipos de agrupación del sistema. IDEMPOTENTE.
 */
class TiposOrganizacionSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'clave' => 'escuela',
                'nombre' => 'Escuela',
                'vocabulario_persona' => 'alumno',
                'vocabulario_agrupacion' => 'grupo',
            ],
            [
                'clave' => 'empresa',
                'nombre' => 'Empresa',
                'vocabulario_persona' => 'colaborador',
                'vocabulario_agrupacion' => 'vacante',
            ],
            [
                'clave' => 'consultorio',
                'nombre' => 'Consultorio',
                'vocabulario_persona' => 'paciente',
                'vocabulario_agrupacion' => 'cohorte',
            ],
            [
                'clave' => 'dependencia',
                'nombre' => 'Dependencia',
                'vocabulario_persona' => 'persona',
                'vocabulario_agrupacion' => 'programa',
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoOrganizacion::query()->updateOrCreate(['clave' => $tipo['clave']], $tipo);
        }

        /*
         * Plantillas del sistema (organizacion_id NULL): disponibles para
         * todos los tenants. Cada organización puede crear las suyas encima sin
         * migrar nada.
         */
        $agrupaciones = [
            ['clave' => 'grupo_escolar', 'nombre' => 'Grupo escolar'],
            ['clave' => 'generacion', 'nombre' => 'Generación'],
            ['clave' => 'taller', 'nombre' => 'Taller'],
            ['clave' => 'vacante', 'nombre' => 'Vacante'],
            ['clave' => 'cohorte', 'nombre' => 'Cohorte'],
            ['clave' => 'centro_trabajo', 'nombre' => 'Centro de trabajo'],
        ];

        foreach ($agrupaciones as $agrupacion) {
            TipoAgrupacion::query()->updateOrCreate(
                ['organizacion_id' => null, 'clave' => $agrupacion['clave']],
                $agrupacion
            );
        }
    }
}
