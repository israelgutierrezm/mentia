<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use App\Domain\Catalogo\Excepciones\HabilitacionInvalida;
use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Habilitación de instrumentos por tenant, con los tres flujos del
 * licenciamiento estructural (principio P8).
 *
 * La distinción no es administrativa: decide si la plataforma puede servir el
 * contenido de una prueba con copyright, y esa responsabilidad es del tenant
 * que declara tener la licencia. Lo que queda registrado —el texto firmado,
 * quién lo firmó, cuándo— es la cadena de responsabilidad que exige el
 * Doc 06 §3.
 */
class GestorTenantInstrumentos
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * Dominio público: se habilita directo. El contenido ya está y es de todos.
     *
     * @throws HabilitacionInvalida
     */
    public function habilitarDominioPublico(VersionInstrumento $version): TenantInstrumento
    {
        $instrumento = $version->instrumento;

        if ($instrumento->estatus_licencia !== Instrumento::DOMINIO_PUBLICO) {
            throw HabilitacionInvalida::porNoSerDominioPublico($instrumento->nombre);
        }

        $this->exigirVersionPublicada($version);

        $registro = $this->registro($version);

        $registro->update([
            'estado' => TenantInstrumento::HABILITADO,
            'origen_contenido' => 'global',
            'habilitado_en' => Carbon::now(),
        ]);

        return $registro;
    }

    /**
     * Instrumento con copyright: la organización DECLARA que tiene la licencia.
     *
     * Queda en `pendiente_contenido`, no en `habilitado`: declarar la licencia
     * no pone los reactivos. Habilitarlo aquí dejaría asignable una prueba
     * vacía, y alguien se sentaría a contestar una pantalla en blanco.
     *
     * @throws HabilitacionInvalida
     */
    public function declararLicencia(
        VersionInstrumento $version,
        Persona $firmante,
        string $textoDeclaracion,
        ?int $evidenciaMediaId = null,
    ): TenantInstrumento {
        $instrumento = $version->instrumento;

        if (! $instrumento->exigeLicenciaDelTenant()) {
            throw HabilitacionInvalida::porNoRequerirLicencia($instrumento->nombre);
        }

        if (trim($textoDeclaracion) === '') {
            /*
             * El TEXTO, no un booleano. Ante una reclamación editorial, "el
             * tenant marcó una casilla" no es defensa; el texto firmado con
             * nombre y fecha sí.
             */
            throw HabilitacionInvalida::porDeclaracionVacia();
        }

        $this->exigirVersionPublicada($version);

        $registro = $this->registro($version);

        $registro->update([
            'estado' => TenantInstrumento::PENDIENTE_CONTENIDO,
            'origen_contenido' => 'capturado_por_tenant',
            'declaracion_licencia_texto' => $textoDeclaracion,
            'declaracion_firmada_por' => $firmante->id,
            'declaracion_firmada_en' => Carbon::now(),
            'evidencia_media_id' => $evidenciaMediaId,
        ]);

        return $registro;
    }

    /**
     * Enciende un instrumento licenciado una vez que su contenido está.
     *
     * @throws HabilitacionInvalida
     */
    public function habilitarTrasCapturarContenido(TenantInstrumento $registro): TenantInstrumento
    {
        if (! $registro->tieneDeclaracionFirmada()) {
            throw HabilitacionInvalida::porFaltarDeclaracion();
        }

        $reactivosPropios = Reactivo::query()
            ->where('version_instrumento_id', $registro->version_instrumento_id)
            ->where('organizacion_id_contenido', $registro->organizacion_id)
            ->count();

        if ($reactivosPropios === 0) {
            throw HabilitacionInvalida::porNoHaberContenidoCapturado();
        }

        $registro->update([
            'estado' => TenantInstrumento::HABILITADO,
            'habilitado_en' => Carbon::now(),
        ]);

        return $registro;
    }

    /**
     * La plataforma apaga un instrumento para un tenant (impago, retiro de
     * licencia, incidencia). No se borra: el rastro de que estuvo habilitado
     * importa para las aplicaciones que ya ocurrieron.
     */
    public function bloquear(TenantInstrumento $registro): TenantInstrumento
    {
        $registro->update(['estado' => TenantInstrumento::BLOQUEADO]);

        return $registro;
    }

    /**
     * @throws HabilitacionInvalida
     */
    private function exigirVersionPublicada(VersionInstrumento $version): void
    {
        if (! $version->estaPublicada()) {
            /*
             * Un borrador no se habilita. Su contenido todavía puede cambiar, y
             * una aplicación contra un borrador no sería reproducible: es la
             * misma razón por la que publicar congela.
             */
            throw HabilitacionInvalida::porNoEstarPublicada($version->version);
        }
    }

    private function registro(VersionInstrumento $version): TenantInstrumento
    {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        return DB::transaction(
            fn (): TenantInstrumento => TenantInstrumento::query()->firstOrCreate(
                [
                    'organizacion_id' => $organizacionId,
                    'version_instrumento_id' => $version->id,
                ],
                ['estado' => TenantInstrumento::DISPONIBLE]
            )
        );
    }
}
