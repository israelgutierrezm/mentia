<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Consentimientos\Modelos\TipoConsentimiento;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\Proposito;
use App\Domain\Evaluaciones\Servicios\CreadorAsignaciones;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Personas\Modelos\Persona;

/**
 * Monta asignaciones listas para probar, con su instrumento publicado y su
 * propósito.
 */
class EscenarioAsignacion
{
    public Proposito $proposito;

    public InstrumentoSintetico $instrumento;

    public function __construct(public EscenarioTenant $tenant)
    {
        $this->instrumento = new InstrumentoSintetico('para_asignar_'.uniqid());
        $this->instrumento->escala('E1');
        $this->instrumento->reactivo('likert_5', 'E1');

        app(\App\Domain\Catalogo\Servicios\PublicadorVersion::class)
            ->publicar($this->instrumento->version);

        $this->proposito = Proposito::query()->create([
            'organizacion_id' => null,
            'clave' => 'proposito_'.uniqid(),
            'nombre' => 'Propósito de prueba',
            'version_instrumento_id' => $this->instrumento->version->id,
            'tipo_consentimiento_id' => TipoConsentimiento::query()
                ->where('clave', TipoConsentimiento::TRATAMIENTO)->value('id'),
            'vigencia_dias_default' => 7,
            'modo_presentacion_default' => 'adulto',
        ]);
    }

    /**
     * @param  list<Persona>  $personas
     */
    public function individual(
        Persona $autor,
        array $personas,
        bool $esDiscreta = false,
        bool $esAnonima = false,
        ?string $ventanaFin = null,
    ): Asignacion {
        return app(CreadorAsignaciones::class)->crear(
            proposito: $this->proposito,
            autor: $autor,
            origenTipo: 'individual',
            destinatariosUuid: array_map(
                static fn (Persona $persona): string => $persona->uuid,
                $personas
            ),
            ventanaFin: $ventanaFin,
            esDiscreta: $esDiscreta,
            esAnonima: $esAnonima,
        );
    }

    public function grupal(
        Persona $autor,
        Agrupacion $agrupacion,
        bool $incluirNuevos = false,
        ?string $ventanaFin = null,
    ): Asignacion {
        return app(CreadorAsignaciones::class)->crear(
            proposito: $this->proposito,
            autor: $autor,
            origenTipo: 'agrupacion',
            agrupacion: $agrupacion,
            ventanaFin: $ventanaFin,
            incluirNuevosMiembros: $incluirNuevos,
        );
    }
}
