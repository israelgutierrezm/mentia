<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Database\Seeder;

/**
 * Siembra el catálogo global del sistema. IDEMPOTENTE: se puede correr encima
 * de una base ya sembrada sin duplicar nada.
 *
 * NO crea organizaciones ni personas de ejemplo: eso es trabajo del alta real
 * (CreadorOrganizacion) o de una factory en las pruebas. Un tenant de demo
 * sembrado aquí terminaría en producción.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Sin restricción de tenant: los global scopes fallan cerrado, así que
         * un seeder sin organización activa no vería —ni podría comprobar la
         * existencia de— nada de lo que va a sembrar.
         */
        app(ContextoOrganizacion::class)->sinRestriccion(function (): void {
            $this->call([
                PermisosSeeder::class,
                NivelesSensibilidadSeeder::class,
                TiposOrganizacionSeeder::class,
                PlantillasRolSeeder::class,
                ExpedienteSeeder::class,
                ConsentimientosSeeder::class,
                CatalogoSeeder::class,
            ]);
        });
    }
}
