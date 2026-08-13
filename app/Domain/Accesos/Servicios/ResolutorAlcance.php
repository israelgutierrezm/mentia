<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Consentimientos\Modelos\ComparticionExpediente;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use Illuminate\Support\Collection;

/**
 * Dimensión 2: ¿el sujeto cae dentro del alcance del actor?
 *
 * Aparte de AccesoService porque tiene sustancia propia —la jerarquía de
 * unidades, la vigencia de membresías, el alcance implícito de titular y
 * tutor— y porque así se puede probar sola.
 */
class ResolutorAlcance
{
    /**
     * Los alcances vigentes del actor en la organización, ya cargados.
     *
     * @return Collection<int, PersonaRolAlcance>
     */
    public function alcancesVigentes(Persona $actor, int $organizacionId): Collection
    {
        /** @var Collection<int, PersonaRolAlcance> */
        return PersonaRolAlcance::query()
            ->withoutGlobalScopes()
            ->where('persona_id', $actor->id)
            ->where('organizacion_id', $organizacionId)
            ->vigentes()
            ->get();
    }

    /**
     * ¿Alguno de los alcances del actor contiene al sujeto?
     */
    public function alcanza(Persona $actor, Persona $sujeto, int $organizacionId): bool
    {
        /*
         * Alcance implícito sobre sí misma. Va PRIMERO y sin consultar nada:
         * el titular llega a su propio expediente sin que nadie le otorgue un
         * alcance, y la mayoría de las personas del sistema no tienen ninguna
         * fila en persona_rol_alcances.
         */
        if ($actor->id === $sujeto->id) {
            return true;
        }

        // Alcance implícito del tutor VIGENTE sobre su tutelado. Vigente
        // significa validada y dentro de fechas: el parentesco declarado por
        // quien se registra no acredita nada (ver Tutoria::estaVigente).
        if ($this->esTutorVigenteDe($actor, $sujeto)) {
            return true;
        }

        $alcances = $this->alcancesVigentes($actor, $organizacionId);

        if ($alcances->isEmpty()) {
            return false;
        }

        foreach ($alcances as $alcance) {
            if ($this->alcanceContiene($alcance, $sujeto, $organizacionId)) {
                return true;
            }
        }

        return false;
    }

    public function esTutorVigenteDe(Persona $actor, Persona $sujeto): bool
    {
        return Tutoria::query()
            ->where('tutor_persona_id', $actor->id)
            ->where('menor_persona_id', $sujeto->id)
            ->vigentes()
            ->exists();
    }

    private function alcanceContiene(
        PersonaRolAlcance $alcance,
        Persona $sujeto,
        int $organizacionId,
    ): bool {
        return match ($alcance->alcance_tipo) {
            PersonaRolAlcance::TIPO_ORGANIZACION => $this->enLaOrganizacion(
                $sujeto, (int) $alcance->alcance_id, $organizacionId
            ),
            PersonaRolAlcance::TIPO_UNIDAD => $this->enLaUnidadODescendientes(
                $sujeto, (int) $alcance->alcance_id, $organizacionId
            ),
            PersonaRolAlcance::TIPO_AGRUPACION => $this->enLaAgrupacion(
                $sujeto, (int) $alcance->alcance_id
            ),
            PersonaRolAlcance::TIPO_PERSONA => $sujeto->id === (int) $alcance->alcance_id,
            default => false,
        };
    }

    /**
     * Alcance de organización completa: el sujeto tiene que estar VINCULADO y
     * con vínculo activo. Estar en la misma organización no basta si ya se dio
     * de baja.
     */
    private function enLaOrganizacion(
        Persona $sujeto,
        int $alcanceOrganizacionId,
        int $organizacionId,
    ): bool {
        // El alcance sólo vale dentro de su propia organización. Sin esta
        // comprobación, una fila con alcance_id de otro tenant concedería
        // acceso cruzado.
        if ($alcanceOrganizacionId !== $organizacionId) {
            return false;
        }

        $vinculada = $sujeto->vinculaciones()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacionId)
            ->where('estado', 'activa')
            ->exists();

        if ($vinculada) {
            return true;
        }

        /*
         * O con una COMPARTICIÓN vigente hacia esta organización.
         *
         * Es el expediente de vida cross-tenant: la persona que fue evaluada
         * en la escuela llega años después a una empresa y decide abrirle
         * parte de su historial. Sin esto, la compartición no serviría de
         * nada —la persona nunca estaría en el alcance de nadie de la
         * organización destino— y la compuerta de consentimiento no llegaría
         * ni a preguntarse.
         *
         * Sólo aplica al alcance de ORGANIZACIÓN COMPLETA. Un alcance por
         * unidad o por agrupación sigue exigiendo membresía, y una persona
         * compartida no está en ningún grupo del destino: quien la ve es quien
         * ve a toda la organización, no un docente con su grupo.
         */
        return ComparticionExpediente::query()
            ->where('persona_id', $sujeto->id)
            ->where('organizacion_destino_id', $organizacionId)
            ->vigentes()
            ->exists();
    }

    /**
     * Alcance por unidad, INCLUYENDO descendientes (Doc 06 §1).
     *
     * La persona pertenece a una unidad a través de sus agrupaciones: quien
     * está en el grupo 3°A del plantel Norte está en el plantel Norte. Se
     * exige membresía VIGENTE — un alumno dado de baja en julio deja de estar
     * en el alcance del coordinador en septiembre.
     */
    private function enLaUnidadODescendientes(
        Persona $sujeto,
        int $unidadId,
        int $organizacionId,
    ): bool {
        $unidad = Unidad::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacionId)
            ->find($unidadId);

        if ($unidad === null) {
            return false;
        }

        $unidades = $unidad->idsConDescendientes();

        return AgrupacionMiembro::query()
            ->where('persona_id', $sujeto->id)
            ->vigentes()
            ->whereHas('agrupacion', function ($consulta) use ($unidades, $organizacionId): void {
                $consulta->withoutGlobalScopes()
                    ->where('organizacion_id', $organizacionId)
                    ->whereIn('unidad_id', $unidades);
            })
            ->exists();
    }

    private function enLaAgrupacion(Persona $sujeto, int $agrupacionId): bool
    {
        return AgrupacionMiembro::query()
            ->where('persona_id', $sujeto->id)
            ->where('agrupacion_id', $agrupacionId)
            ->vigentes()
            ->exists();
    }
}
