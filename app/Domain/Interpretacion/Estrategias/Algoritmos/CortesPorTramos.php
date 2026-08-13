<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Base de los algoritmos que clasifican UN puntaje total en tramos.
 *
 * Es la forma que comparten el PHQ-9 y el AUDIT: un total, unos cortes
 * publicados y una categoría. Lo que cambia entre ellos son los números y los
 * nombres, no el procedimiento.
 *
 * Los cortes se pueden sobrescribir desde el pipeline con el parámetro
 * `cortes`; cada subclase trae los suyos por omisión, que es lo que hace que un
 * instrumento recién cargado califique sin configurar nada.
 */
abstract class CortesPorTramos implements EstrategiaCalificacion
{
    use ClasificaEnTramos;

    public static function etapa(): string
    {
        return 'algoritmos';
    }

    /**
     * Los tramos por omisión, de menor a mayor: `[minimo, etiqueta]`.
     *
     * @return list<array{0: float, 1: string}>
     */
    abstract protected function tramosPorOmision(): array;

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        $claveEscala = $parametros['escala'] ?? null;

        if ($claveEscala === null) {
            /*
             * Sin escala configurada no hay nada que clasificar. Se sale en
             * silencio en vez de adivinar la primera: adivinar pondría una
             * etiqueta de gravedad sobre una escala cualquiera, y eso se lee
             * como resultado.
             */
            return;
        }

        $bruto = $contexto->bruto($claveEscala);

        if ($bruto === null) {
            return;
        }

        $tramos = isset($parametros['cortes'])
            ? $this->leerTramos($parametros['cortes'])
            : $this->tramosPorOmision();

        if ($tramos === []) {
            $tramos = $this->tramosPorOmision();
        }

        $contexto->etiquetas[$claveEscala] = $this->clasificarEn($bruto, $tramos);
    }
}
