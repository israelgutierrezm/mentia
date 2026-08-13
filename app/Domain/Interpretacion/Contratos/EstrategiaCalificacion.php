<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Contratos;

use App\Domain\Interpretacion\Datos\ContextoCalificacion;

/**
 * Una forma concreta de hacer una etapa de la calificación.
 *
 * Sumar un likert, contar aciertos, aplicar los cortes de la NOM-035: son
 * intercambiables porque todas hacen lo mismo desde fuera —reciben el contexto
 * de una aplicación y dejan su salida en él—. Agregar una nueva es escribir
 * una clase y registrarla; el pipeline no se entera.
 *
 * Es lo que impide que el motor acabe siendo un `switch` de doscientos casos
 * con el nombre de cada instrumento.
 */
interface EstrategiaCalificacion
{
    /**
     * La clave con la que el catálogo la nombra: `suma_ponderada`,
     * `mchat_dos_etapas`. Es lo que se guarda en `instrumento_pipeline`, y por
     * eso no puede cambiar sin migrar datos.
     */
    public static function clave(): string;

    /**
     * A qué etapa pertenece. El registro lo usa para no dejar que una
     * estrategia de brutos se cuele en la etapa de normalización.
     */
    public static function etapa(): string;

    /**
     * @param  array<string, string>  $parametros  Los de `instrumento_pipeline_parametros`.
     */
    public function ejecutar(ContextoCalificacion $contexto, array $parametros): void;
}
