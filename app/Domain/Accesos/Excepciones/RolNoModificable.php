<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Excepciones;

use RuntimeException;

class RolNoModificable extends RuntimeException
{
    public static function porAutoEncierro(): self
    {
        return new self(
            'No puedes quitarle la administración de roles al rol con el que estás '
            .'trabajando: te quedarías sin forma de volver a entrar aquí.'
        );
    }

    public static function porTenerAlcancesVivos(int $cuantos): self
    {
        return new self(
            "Ese rol está asignado a {$cuantos} persona(s). Retira sus alcances antes de "
            .'eliminarlo: borrarlo se los llevaría por delante sin dejar rastro de quién '
            .'tenía qué.'
        );
    }

    public static function porPermisoDesconocido(string $permiso): self
    {
        return new self(
            "El permiso «{$permiso}» no existe en el catálogo del sistema. Los permisos "
            .'son llaves que el código consulta, no texto libre.'
        );
    }
}
