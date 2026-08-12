<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accesos\Modelos\NivelSensibilidad;
use Illuminate\Database\Seeder;

/**
 * Los cuatro niveles del Doc 03 §M3. IDEMPOTENTE.
 *
 * La escala no es decorativa: es lo que impide que un reclutador vea un
 * PHQ-9 con el mismo permiso con el que ve un test de razonamiento, y eso es
 * no discriminación laboral (Doc 06 §3), no una preferencia de producto.
 */
class NivelesSensibilidadSeeder extends Seeder
{
    public function run(): void
    {
        $niveles = [
            [
                'nivel' => 1,
                'clave' => 'general',
                'nombre' => 'General',
                'descripcion' => 'Datos de identificación y resultados sin carga clínica ni laboral.',
            ],
            [
                'nivel' => 2,
                'clave' => 'laboral',
                'nombre' => 'Laboral',
                'descripcion' => 'Aptitudes, intereses, personalidad laboral y clima. Visible en procesos de selección.',
            ],
            [
                'nivel' => 3,
                'clave' => 'psicologico',
                'nombre' => 'Psicológico',
                'descripcion' => 'Tamizajes de desarrollo y perfiles psicológicos. Fuera del contexto laboral.',
            ],
            [
                'nivel' => 4,
                'clave' => 'clinico',
                'nombre' => 'Clínico',
                'descripcion' => 'Sintomatología, riesgo y notas profesionales. Sólo profesional autorizado.',
            ],
        ];

        foreach ($niveles as $nivel) {
            NivelSensibilidad::query()->updateOrCreate(
                ['nivel' => $nivel['nivel']],
                $nivel
            );
        }
    }
}
