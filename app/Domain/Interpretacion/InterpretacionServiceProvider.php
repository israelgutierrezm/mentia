<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion;

use App\Domain\Interpretacion\Estrategias\Algoritmos\CortesNom035;
use App\Domain\Interpretacion\Estrategias\Algoritmos\GravedadPhq;
use App\Domain\Interpretacion\Estrategias\Algoritmos\MchatDosEtapas;
use App\Domain\Interpretacion\Estrategias\Algoritmos\ZonasAudit;
use App\Domain\Interpretacion\Estrategias\Brutos\ConteoCorrectas;
use App\Domain\Interpretacion\Estrategias\Brutos\ConteoCriterio;
use App\Domain\Interpretacion\Estrategias\Brutos\ConteoIpsativo;
use App\Domain\Interpretacion\Estrategias\Brutos\RankingPonderado;
use App\Domain\Interpretacion\Estrategias\Brutos\SumaPonderada;
use App\Domain\Interpretacion\Estrategias\Brutos\SumaSimple;
use App\Domain\Interpretacion\Estrategias\Validez\OmisionesMaximas;
use App\Domain\Interpretacion\Estrategias\Validez\PatronRepetido;
use App\Domain\Interpretacion\Estrategias\Validez\TiempoAtipico;
use App\Domain\Interpretacion\Servicios\RegistroEstrategias;
use Illuminate\Support\ServiceProvider;

/**
 * Interpretación — M8 y M10.
 *
 * Convierte respuestas en puntajes y puntajes en texto: el pipeline de las seis
 * etapas, las reglas de interpretación con sus variables, los perfiles tipo y
 * los comparadores (contra perfil de puesto, contra sí misma en el tiempo,
 * contra el grupo).
 *
 * El sistema SUGIERE, NUNCA DIAGNOSTICA (principio P6). Toda salida se redacta
 * como "perfil compatible con" o "se sugiere canalización"; el diagnóstico y la
 * firma son actos del profesional humano.
 *
 * AQUÍ SE REGISTRAN LAS ESTRATEGIAS. Es el único lugar donde el sistema sabe
 * qué claves de calificación existen: agregar un algoritmo nuevo es escribir su
 * clase y sumar una línea a esta lista, sin tocar el pipeline ni las
 * migraciones. Un tenant con una lógica propia registra la suya desde su propio
 * ServiceProvider.
 */
class InterpretacionServiceProvider extends ServiceProvider
{
    /**
     * Contrato => implementación. Un dominio se consume por sus contratos:
     * ni los controllers ni los otros dominios instancian sus servicios.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [];

    /**
     * Las estrategias de la Ola 1 (Doc 08, Fase 7).
     *
     * @var list<class-string<Contratos\EstrategiaCalificacion>>
     */
    private const ESTRATEGIAS = [
        // Etapa 1 — validez previa.
        OmisionesMaximas::class,
        PatronRepetido::class,
        TiempoAtipico::class,

        // Etapa 2 — puntajes brutos.
        SumaSimple::class,
        SumaPonderada::class,
        ConteoCorrectas::class,
        ConteoIpsativo::class,
        RankingPonderado::class,
        ConteoCriterio::class,

        // Etapa 3 — algoritmos especiales.
        MchatDosEtapas::class,
        CortesNom035::class,
        ZonasAudit::class,
        GravedadPhq::class,
    ];

    public function register(): void
    {
        $this->app->singleton(RegistroEstrategias::class, function (): RegistroEstrategias {
            $registro = new RegistroEstrategias;

            foreach (self::ESTRATEGIAS as $clase) {
                $registro->registrar(new $clase);
            }

            return $registro;
        });
    }
}
