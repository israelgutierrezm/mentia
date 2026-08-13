<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Estrategias\Algoritmos;

use App\Domain\Interpretacion\Contratos\EstrategiaCalificacion;
use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * `nom035_cortes` (Doc 05 §2).
 *
 * La NOM-035-STPS-2018 no clasifica un total: clasifica el total, cada
 * CATEGORÍA y cada DOMINIO, y cada nivel tiene cortes propios publicados en el
 * DOF. Un dominio de tres reactivos y otro de nueve no se cortan igual.
 *
 * Por eso esta estrategia no trae cortes por omisión: los recibe escala por
 * escala. Inventarle cortes a una norma oficial produciría un semáforo que se
 * ve como el de la norma y no lo es, en un documento que la empresa entrega a
 * la autoridad.
 *
 * Parámetros: uno por escala, con la forma `escala:<clave>` y por valor los
 * tramos `0:nulo|5:bajo|9:medio|11:alto|14:muy_alto`.
 */
class CortesNom035 implements EstrategiaCalificacion
{
    use ClasificaEnTramos;

    public static function clave(): string
    {
        return 'nom035_cortes';
    }

    public static function etapa(): string
    {
        return 'algoritmos';
    }

    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void
    {
        foreach ($parametros as $clave => $valor) {
            if (! str_starts_with($clave, 'escala:')) {
                continue;
            }

            $claveEscala = substr($clave, strlen('escala:'));
            $bruto = $contexto->bruto($claveEscala);

            if ($bruto === null) {
                continue;
            }

            $tramos = $this->leerTramos($valor);

            if ($tramos === []) {
                continue;
            }

            $contexto->etiquetas[$claveEscala] = $this->clasificarEn($bruto, $tramos);
        }
    }
}
