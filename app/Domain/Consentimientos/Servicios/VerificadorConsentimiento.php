<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Servicios;

use App\Domain\Consentimientos\Contratos\VerificaConsentimiento;
use App\Domain\Consentimientos\Datos\EstadoConsentimiento;
use App\Domain\Consentimientos\Modelos\ComparticionExpediente;
use App\Domain\Consentimientos\Modelos\Consentimiento;
use App\Domain\Consentimientos\Modelos\TipoConsentimiento;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;

/**
 * La cuarta dimensión, de verdad (Doc 06 §1, punto 4). Sustituye al stub
 * `ConsentimientoPendiente` de la Fase 1.
 *
 * Contesta una sola pregunta: ¿hay consentimiento vigente de la persona —o de
 * su tutor— que ampare este tratamiento, para este propósito y ante este
 * tenant?
 *
 * Dos salidas negativas distintas y las dos cierran el paso: `NoAmparado`. La
 * antigua `Pendiente` ya no la devuelve nadie en producción; se conserva en el
 * enum porque la bitácora de la Fase 1 tiene registros que la citan y borrar
 * el caso volvería ilegibles esas filas.
 */
class VerificadorConsentimiento implements VerificaConsentimiento
{
    /**
     * Acciones que NO exigen consentimiento.
     *
     * Consultar el padrón o dar de alta a alguien es operación administrativa
     * del tenant sobre datos de identificación, no tratamiento de datos
     * sensibles. Exigir consentimiento aquí dejaría al tenant sin poder dar de
     * alta a la persona a la que después le va a pedir el consentimiento.
     *
     * @var list<string>
     */
    private const SIN_CONSENTIMIENTO = [
        'personas.ver',
        'personas.crear',
        'personas.vincular',
        'tutorias.validar',
    ];

    public function estadoPara(
        Persona $sujeto,
        string $accion,
        ?int $propositoId,
        ?int $organizacionId,
    ): EstadoConsentimiento {
        if (in_array($accion, self::SIN_CONSENTIMIENTO, true)) {
            return EstadoConsentimiento::Amparado;
        }

        if ($organizacionId === null) {
            return EstadoConsentimiento::NoAmparado;
        }

        /*
         * Si la persona NO está vinculada a este tenant, lo que se está
         * pidiendo es acceso cross-tenant: no basta el consentimiento de
         * tratamiento, hace falta una compartición vigente que la persona haya
         * decidido (Doc 06 §1).
         */
        if (! $this->estaVinculada($sujeto, $organizacionId)) {
            return $this->hayComparticionVigente($sujeto, $organizacionId)
                ? EstadoConsentimiento::Amparado
                : EstadoConsentimiento::NoAmparado;
        }

        return $this->hayConsentimientoVigente($sujeto, $accion, $propositoId, $organizacionId)
            ? EstadoConsentimiento::Amparado
            : EstadoConsentimiento::NoAmparado;
    }

    private function estaVinculada(Persona $sujeto, int $organizacionId): bool
    {
        return OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->where('persona_id', $sujeto->id)
            ->where('organizacion_id', $organizacionId)
            ->where('estado', 'activa')
            ->exists();
    }

    private function hayComparticionVigente(Persona $sujeto, int $organizacionId): bool
    {
        return ComparticionExpediente::query()
            ->where('persona_id', $sujeto->id)
            ->where('organizacion_destino_id', $organizacionId)
            ->vigentes()
            ->exists();
    }

    private function hayConsentimientoVigente(
        Persona $sujeto,
        string $accion,
        ?int $propositoId,
        int $organizacionId,
    ): bool {
        $claves = $this->tiposQueAmparan($accion);

        return Consentimiento::query()
            ->where('persona_id', $sujeto->id)
            ->vigentes()
            /*
             * Ampara si es de ESTE tenant o de la plataforma (organizacion_id
             * NULL): el consentimiento de tratamiento general que la persona
             * dio una vez no se vuelve a pedir en cada organización.
             */
            ->where(function ($consulta) use ($organizacionId): void {
                $consulta->whereNull('organizacion_id')
                    ->orWhere('organizacion_id', $organizacionId);
            })
            /*
             * Un consentimiento sin propósito ampara cualquiera; uno con
             * propósito ampara SÓLO el suyo. Es lo que impide que el permiso
             * firmado para un tamizaje escolar sirva para un proceso de
             * selección laboral (Doc 06 §3).
             */
            ->where(function ($consulta) use ($propositoId): void {
                $consulta->whereNull('proposito_id');

                if ($propositoId !== null) {
                    $consulta->orWhere('proposito_id', $propositoId);
                }
            })
            ->whereHas('texto', function ($consulta) use ($claves): void {
                $consulta->whereHas(
                    'tipo',
                    fn ($sub) => $sub->whereIn('clave', $claves)
                );
            })
            ->exists();
    }

    /**
     * Qué finalidades amparan una acción.
     *
     * El tratamiento general ampara todo lo demás: quien consintió que se
     * traten sus datos sensibles consintió que se le apliquen instrumentos.
     * Lo que NO ampara es la compartición cross-tenant, que va aparte
     * justamente porque es otra decisión.
     *
     * @return list<string>
     */
    private function tiposQueAmparan(string $accion): array
    {
        $base = [TipoConsentimiento::TRATAMIENTO];

        return match (true) {
            str_starts_with($accion, 'expediente.') => [
                ...$base,
                TipoConsentimiento::EDUCATIVA,
                TipoConsentimiento::LABORAL,
                TipoConsentimiento::CLINICA,
            ],
            str_starts_with($accion, 'evaluaciones.'),
            str_starts_with($accion, 'resultados.'),
            str_starts_with($accion, 'protocolos.') => [
                ...$base,
                TipoConsentimiento::EDUCATIVA,
                TipoConsentimiento::LABORAL,
                TipoConsentimiento::CLINICA,
            ],
            default => $base,
        };
    }
}
