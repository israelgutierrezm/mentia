<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Contratos;

/**
 * Quien redacta el borrador integrador.
 *
 * Existe como contrato para que las pruebas no llamen a una API de pago ni
 * dependan de la red, y para que cambiar de proveedor sea cambiar un binding.
 *
 * La implementación devuelve TEXTO. Lo que ese texto significa, si se entrega y
 * quién responde por él no es asunto suyo: eso lo decide una persona con cédula
 * más adelante (principio P6).
 */
interface RedactaBorradores
{
    /**
     * @param  array<string, mixed>  $insumo  Resultados ya calificados e interpretados.
     */
    public function redactar(array $insumo): string;

    /** El modelo que redactó, para dejarlo registrado. */
    public function modelo(): string;
}
