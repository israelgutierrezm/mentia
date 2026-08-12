<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Contratos;

/**
 * Un recurso que declara su nivel de sensibilidad (1 general … 4 clínico).
 *
 * Lo implementan los modelos cuyo contenido tiene grado: resultados,
 * documentos de expediente, notas profesionales. Un recurso que no lo
 * implementa vale 1 —general—, que es el nivel que cualquier rol alcanza.
 *
 * El default es 1 y no 4 porque el nivel debe declararlo quien SABE que su
 * contenido es sensible. Con default 4, todo lo que nadie clasificó se
 * volvería invisible para casi todos los roles y el sistema parecería roto;
 * con default 1, lo que falta clasificar se nota al revisarlo, no al usarlo.
 * Los modelos sensibles de las fases 2 y 7 lo implementan explícitamente.
 */
interface TieneSensibilidad
{
    public function nivelSensibilidad(): int;
}
