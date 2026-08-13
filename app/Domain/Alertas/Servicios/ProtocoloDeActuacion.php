<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Alertas\Modelos\AlertaDestinatario;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Organizaciones\Modelos\OrganizacionConfiguracion;
use Illuminate\Support\Carbon;

/**
 * El protocolo de actuación del tenant (Doc 06 §5).
 *
 * REQUISITO ÉTICO, no burocrático. Un instrumento con reactivos centinela
 * detecta ideación suicida. Habilitarlo sin haber definido quién responde, en
 * cuánto tiempo y a dónde se canaliza produce exactamente esto: una alerta
 * crítica a las once de la noche en un buzón que nadie mira hasta el lunes.
 *
 * Por eso el sistema NO deja asignar un instrumento con centinelas hasta que la
 * organización registró su protocolo y designó destinatarios. Es la única
 * comprobación del sistema que existe para proteger a quien contesta, no a los
 * datos.
 */
class ProtocoloDeActuacion
{
    public const CLAVE_TEXTO = 'protocolo_actuacion.texto';

    public const CLAVE_REGISTRADO_EN = 'protocolo_actuacion.registrado_en';

    public const CLAVE_REGISTRADO_POR = 'protocolo_actuacion.registrado_por';

    public const CLAVE_RECURSOS = 'recursos_apoyo.texto';

    /**
     * ¿Puede esta organización asignar esta versión de instrumento?
     *
     * Se define EN TÉRMINOS de `motivoDeBloqueo()` a propósito. Escrita aparte
     * con su propia condición, las dos empezarían iguales y divergirían al
     * primer cambio: la pantalla diría que sí se puede asignar y la creación lo
     * rechazaría, o —mucho peor— al revés.
     */
    public function permiteAsignar(Organizacion $organizacion, VersionInstrumento $version): bool
    {
        return $this->motivoDeBloqueo($organizacion, $version) === null;
    }

    /**
     * Por qué NO se puede, en palabras que digan qué falta.
     *
     * Un "no se puede asignar" a secas manda a quien administra a adivinar; lo
     * que hace falta es que sepa exactamente qué le falta configurar.
     */
    public function motivoDeBloqueo(Organizacion $organizacion, VersionInstrumento $version): ?string
    {
        if (! $this->tieneCentinelas($version)) {
            return null;
        }

        $faltantes = [];

        if (! $this->registrado($organizacion)) {
            $faltantes[] = 'registrar el protocolo de actuación';
        }

        if (! $this->tieneDestinatarios($organizacion)) {
            $faltantes[] = 'designar a quién se notifican las alertas críticas';
        }

        if ($faltantes === []) {
            return null;
        }

        return sprintf(
            'Este instrumento tiene reactivos que detectan riesgo. Antes de asignarlo hay que %s.',
            implode(' y ', $faltantes),
        );
    }

    public function tieneCentinelas(VersionInstrumento $version): bool
    {
        return Reactivo::query()
            ->where('version_instrumento_id', $version->id)
            ->where('es_centinela', true)
            ->exists();
    }

    public function registrado(Organizacion $organizacion): bool
    {
        $texto = $this->valor($organizacion, self::CLAVE_TEXTO);

        // Un protocolo de tres palabras no es un protocolo. El mínimo no
        // garantiza calidad, pero sí impide despachar el requisito con un
        // punto.
        return $texto !== null && mb_strlen(trim($texto)) >= 80;
    }

    public function tieneDestinatarios(Organizacion $organizacion): bool
    {
        return AlertaDestinatario::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacion->id)
            ->para('centinela', 'critica')
            ->exists();
    }

    /**
     * Registra el protocolo. Deja constancia de quién y cuándo: es un acto del
     * que alguien responde, no una casilla.
     */
    public function registrar(Organizacion $organizacion, string $texto, int $personaId): void
    {
        $this->guardar($organizacion, self::CLAVE_TEXTO, $texto);
        $this->guardar($organizacion, self::CLAVE_REGISTRADO_EN, Carbon::now()->toIso8601String());
        $this->guardar($organizacion, self::CLAVE_REGISTRADO_POR, (string) $personaId);
    }

    /**
     * Los recursos de apoyo que se le muestran a quien contesta un instrumento
     * sensible al terminar (Doc 05 §3).
     */
    public function recursosDeApoyo(Organizacion $organizacion): ?string
    {
        $texto = $this->valor($organizacion, self::CLAVE_RECURSOS);

        return $texto === null || trim($texto) === '' ? null : $texto;
    }

    private function valor(Organizacion $organizacion, string $clave): ?string
    {
        /*
         * `withoutGlobalScopes`: esto se consulta desde jobs y desde el motor de
         * aplicación, que corren sin organización activa. El scope falla cerrado
         * y devolvería "sin protocolo" para todos, bloqueando asignaciones
         * legítimas.
         */
        return OrganizacionConfiguracion::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacion->id)
            ->where('clave', $clave)
            ->value('valor');
    }

    private function guardar(Organizacion $organizacion, string $clave, string $valor): void
    {
        OrganizacionConfiguracion::query()->withoutGlobalScopes()->updateOrCreate(
            ['organizacion_id' => $organizacion->id, 'clave' => $clave],
            ['valor' => $valor],
        );
    }
}
