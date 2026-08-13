<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Interpretacion\Contratos\RedactaBorradores;

/**
 * El redactor de las pruebas.
 *
 * Guarda el insumo que recibió para poder comprobar QUÉ se le mandó a la IA.
 * Esa comprobación es la que importa: el contrato del Doc 05 §6 dice que el
 * insumo va pseudonimizado, y una prueba que sólo mirara el texto de salida no
 * podría ver si el nombre de la persona viajó al proveedor.
 */
class RedactorFalso implements RedactaBorradores
{
    /** @var array<string, mixed>|null */
    public ?array $ultimoInsumo = null;

    public function __construct(public string $texto = 'Borrador de prueba.') {}

    public function redactar(array $insumo): string
    {
        $this->ultimoInsumo = $insumo;

        return $this->texto;
    }

    public function modelo(): string
    {
        return 'modelo-de-prueba';
    }
}
