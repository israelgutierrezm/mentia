<?php

declare(strict_types=1);

namespace App\Soporte\BaseDatos;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de los modelos del dominio.
 *
 * Existe por una sola razón concreta: el Doc 03 fija `creado_en` y
 * `actualizado_en` como sellos de tiempo de todas las tablas, y Eloquent busca
 * `created_at`/`updated_at`. Sin esto habría que repetir las dos constantes en
 * cada modelo, y el día que a uno se le olviden la tabla se crea igual, migra
 * sin quejarse y revienta con "Unknown column 'created_at'" en el primer
 * `create()`.
 *
 * Los modelos de paquetes (Spatie, medialibrary) NO heredan de aquí: sus
 * tablas son suyas y usan las columnas en inglés.
 */
abstract class Modelo extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';
}
