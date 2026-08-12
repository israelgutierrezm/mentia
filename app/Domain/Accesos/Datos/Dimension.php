<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Datos;

/**
 * Las cuatro dimensiones de la autorización (Doc 06 §1), en su orden de
 * verificación.
 *
 * Existen como enum y no como cadenas sueltas porque la dimensión que falló se
 * escribe en bitácora: con cadenas, un `'sensibilidad'` mal escrito en un
 * punto produce un registro que ninguna consulta de auditoría encuentra.
 */
enum Dimension: string
{
    case Permiso = 'permiso';
    case Alcance = 'alcance';
    case Sensibilidad = 'sensibilidad';
    case Consentimiento = 'consentimiento';

    public function motivoPorOmision(): string
    {
        return match ($this) {
            self::Permiso => 'El rol activo no tiene el permiso requerido.',
            self::Alcance => 'La persona está fuera del alcance del rol activo.',
            self::Sensibilidad => 'El recurso supera el nivel de sensibilidad del rol activo.',
            self::Consentimiento => 'No hay consentimiento vigente que ampare este propósito.',
        };
    }
}
