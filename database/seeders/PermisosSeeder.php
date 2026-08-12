<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accesos\CatalogoPermisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra los permisos del sistema. IDEMPOTENTE.
 *
 * Los permisos son GLOBALES: no llevan organizacion_id aunque Spatie corra en
 * modo teams. Un permiso es una llave del código, la misma para todos los
 * tenants; lo que es de cada organización son sus roles.
 */
class PermisosSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CatalogoPermisos::todos() as $permiso) {
            Permission::findOrCreate($permiso->clave, 'web');
        }

        // Sin esto, cualquier verificación posterior en la misma corrida sigue
        // leyendo la caché anterior y no encuentra lo recién sembrado.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
