<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use App\Domain\Catalogo\Excepciones\VersionInmutable;
use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\FormulaDerivada;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El guardián de la inmutabilidad (principio P4).
 *
 * Publicar una versión la congela. Lo que hay que impedir NO es cambiar el
 * enum `estado`, sino escribir su CONTENIDO —reactivos, opciones, claves,
 * baremos, interpretaciones— porque una aplicación de hace dos años apunta a
 * esta versión exacta y su resultado tiene que seguir siendo reproducible.
 *
 * Toda escritura de contenido pasa por `exigirEditable()`. No hay una segunda
 * puerta: si aparece un servicio que escribe reactivos sin llamarlo, es un
 * agujero, y por eso las pruebas lo intentan.
 */
class PublicadorVersion
{
    /**
     * La comprobación que TODO servicio de contenido debe hacer antes de
     * escribir.
     *
     * @throws VersionInmutable
     */
    public function exigirEditable(VersionInstrumento $version): void
    {
        if ($version->admiteEdicionDeContenido()) {
            return;
        }

        $instrumento = $version->instrumento->nombre;

        throw $version->estado === VersionInstrumento::RETIRADA
            ? VersionInmutable::porEstarRetirada($instrumento, $version->version)
            : VersionInmutable::porEstarPublicada($instrumento, $version->version);
    }

    /**
     * Publica. Antes comprueba que la versión sirva para algo.
     *
     * Las validaciones no son burocracia: una versión publicada se puede
     * asignar, y las tres cosas que se comprueban aquí producen, cada una, una
     * aplicación que falla delante de la persona evaluada en vez de fallar
     * aquí.
     *
     * @throws VersionInmutable
     */
    public function publicar(VersionInstrumento $version): VersionInstrumento
    {
        $this->exigirEditable($version);

        $this->exigirQueTengaContenido($version);
        $this->exigirQueLasEscalasPuntuen($version);
        $this->exigirFormulasResolubles($version);

        return DB::transaction(function () use ($version): VersionInstrumento {
            $version->update([
                'estado' => VersionInstrumento::PUBLICADA,
                'publicada_en' => Carbon::now(),
            ]);

            return $version;
        });
    }

    /**
     * Retira una versión: deja de poder asignarse, pero sus aplicaciones
     * históricas siguen apuntando a ella y siguen siendo legibles.
     */
    public function retirar(VersionInstrumento $version): VersionInstrumento
    {
        $version->update(['estado' => VersionInstrumento::RETIRADA]);

        return $version;
    }

    /**
     * Crea una versión NUEVA en borrador copiando el contenido de otra.
     *
     * Es la forma correcta de "corregir" una versión publicada: no se edita,
     * se clona y se corrige la copia. Sin esto, corregir una errata obligaría
     * a capturar el instrumento entero otra vez y la tentación de editar la
     * publicada sería irresistible.
     */
    public function nuevaVersionDesde(
        VersionInstrumento $origen,
        string $numeroNuevo,
        ?string $notas = null,
    ): VersionInstrumento {
        return DB::transaction(function () use ($origen, $numeroNuevo, $notas): VersionInstrumento {
            /** @var VersionInstrumento $nueva */
            $nueva = VersionInstrumento::query()->create([
                'instrumento_id' => $origen->instrumento_id,
                'version' => $numeroNuevo,
                'idioma' => $origen->idioma,
                'estado' => VersionInstrumento::BORRADOR,
                'notas_version' => $notas,
            ]);

            (new ClonadorContenidoVersion)->clonar($origen, $nueva);

            return $nueva;
        });
    }

    /**
     * @throws VersionInmutable
     */
    private function exigirQueTengaContenido(VersionInstrumento $version): void
    {
        $modo = $version->instrumento->modo_calificacion;

        /*
         * Un instrumento de captura de protocolo NO tiene reactivos por
         * diseño: se capturan sus resultados, no sus respuestas. Exigirle
         * reactivos impediría publicar un WISC.
         */
        if ($modo === 'captura_protocolo') {
            return;
        }

        if ($version->reactivos()->count() === 0) {
            throw VersionInmutable::porNoTenerContenido($version->instrumento->nombre);
        }
    }

    /**
     * Toda escala no derivada necesita al menos una clave que la alimente.
     *
     * @throws VersionInmutable
     */
    private function exigirQueLasEscalasPuntuen(VersionInstrumento $version): void
    {
        if ($version->instrumento->modo_calificacion === 'captura_protocolo') {
            return;
        }

        $derivadas = FormulaDerivada::query()
            ->where('version_instrumento_id', $version->id)
            ->pluck('escala_destino_id')
            ->all();

        $escalas = Escala::query()
            ->where('version_instrumento_id', $version->id)
            ->whereNotIn('id', $derivadas)
            ->withCount('subescalas')
            ->get();

        foreach ($escalas as $escala) {
            // Una escala de segundo orden se alimenta de sus hijas, no de
            // claves propias.
            if (($escala->subescalas_count ?? 0) > 0) {
                continue;
            }

            $tieneClaves = $version->claves()->where('escala_id', $escala->id)->exists();

            if (! $tieneClaves) {
                throw VersionInmutable::porEscalaSinClaves($escala->clave);
            }
        }
    }

    /**
     * Las fórmulas sólo pueden citar claves de escala que existan en esta
     * versión, y sólo con operadores aritméticos.
     *
     * Se valida AQUÍ y no al evaluar porque al evaluar ya hay alguien
     * esperando su resultado. Y se valida con lista blanca: una expresión que
     * llega de una hoja de Excel no se puede pasar por eval().
     *
     * @throws VersionInmutable
     */
    private function exigirFormulasResolubles(VersionInstrumento $version): void
    {
        $claves = Escala::query()
            ->where('version_instrumento_id', $version->id)
            ->pluck('clave')
            ->all();

        $formulas = FormulaDerivada::query()
            ->where('version_instrumento_id', $version->id)
            ->get();

        foreach ($formulas as $formula) {
            $expresion = $formula->expresion;

            // Sólo identificadores, números, operadores y paréntesis.
            if (preg_match('/^[A-Za-z0-9_\s+\-*\/().]+$/', $expresion) !== 1) {
                throw VersionInmutable::porFormulaInvalida(
                    $expresion,
                    'sólo admite claves de escala, números, + - * / y paréntesis.'
                );
            }

            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $expresion, $coincidencias);

            foreach ($coincidencias[0] as $referencia) {
                if (! in_array($referencia, $claves, true)) {
                    throw VersionInmutable::porFormulaInvalida(
                        $expresion,
                        "«{$referencia}» no es una escala de esta versión."
                    );
                }
            }
        }
    }
}
