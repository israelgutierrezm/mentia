<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asigna un rol a una persona CON su alcance y su vigencia.
 *
 * Las dos cosas juntas y en una transacción, porque por separado no
 * significan nada: un rol sin alcance da un permiso que no se puede ejercer
 * sobre nadie, y un alcance sin rol no concede nada. Si el alta se quedara a
 * medias, quedaría una persona con rol de psicóloga y cero acceso —o, según
 * el orden, un alcance colgando de un rol que no tiene—.
 */
class AsignadorRoles
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function asignar(
        Persona $persona,
        Rol $rol,
        string $alcanceTipo,
        ?int $alcanceId,
        ?string $vigenciaInicio = null,
        ?string $vigenciaFin = null,
        ?Persona $otorgadoPor = null,
    ): PersonaRolAlcance {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        // El rol tiene que ser DE ESTE tenant. Sin esta comprobación, mandar
        // el id de un rol ajeno concedería sus permisos aquí.
        if ($rol->organizacion_id !== $organizacionId) {
            throw new InvalidArgumentException('Ese rol no pertenece a la organización activa.');
        }

        $alcanceResuelto = $this->resolverAlcance($alcanceTipo, $alcanceId, $organizacionId);

        return DB::transaction(function () use (
            $persona, $rol, $alcanceTipo, $alcanceResuelto,
            $vigenciaInicio, $vigenciaFin, $otorgadoPor, $organizacionId
        ): PersonaRolAlcance {
            /*
             * assignRole escribe organizacion_id en model_has_roles con lo que
             * tenga el registrar, no con el del rol. El contexto ya lo fijó,
             * pero se vuelve a poner aquí porque este servicio también se
             * llama desde comandos y jobs, donde no hubo middleware.
             */
            $this->registrar->setPermissionsTeamId($organizacionId);
            $persona->assignRole($rol);

            return PersonaRolAlcance::query()->create([
                'persona_id' => $persona->id,
                'rol_id' => $rol->id,
                'alcance_tipo' => $alcanceTipo,
                'alcance_id' => $alcanceResuelto,
                'vigencia_inicio' => $vigenciaInicio ?? Carbon::now()->toDateString(),
                'vigencia_fin' => $vigenciaFin,
                'otorgado_por' => $otorgadoPor?->id,
            ]);
        });
    }

    /**
     * Retira UN alcance. El rol de Spatie sólo se quita cuando ya no le queda
     * ningún alcance: quitarlo con el primero dejaría muertos los demás.
     */
    public function retirar(PersonaRolAlcance $alcance): void
    {
        DB::transaction(function () use ($alcance): void {
            $persona = $alcance->persona;
            $rolId = $alcance->rol_id;
            $organizacionId = $alcance->organizacion_id;

            $alcance->delete();

            $quedan = PersonaRolAlcance::query()
                ->withoutGlobalScopes()
                ->where('persona_id', $persona->id)
                ->where('rol_id', $rolId)
                ->where('organizacion_id', $organizacionId)
                ->exists();

            if ($quedan) {
                return;
            }

            $this->registrar->setPermissionsTeamId($organizacionId);

            $rol = Rol::query()->find($rolId);

            if ($rol !== null) {
                $persona->removeRole($rol);
            }
        });
    }

    /**
     * Comprueba que el ámbito exista y sea de este tenant, y devuelve su id.
     */
    private function resolverAlcance(string $tipo, ?int $alcanceId, int $organizacionId): int
    {
        return match ($tipo) {
            PersonaRolAlcance::TIPO_ORGANIZACION => $organizacionId,

            PersonaRolAlcance::TIPO_UNIDAD => (int) Unidad::query()
                ->findOrFail($alcanceId)->id,

            PersonaRolAlcance::TIPO_AGRUPACION => (int) Agrupacion::query()
                ->findOrFail($alcanceId)->id,

            /*
             * Alcance sobre UNA persona: tiene que estar vinculada a este
             * tenant. Sin la comprobación se podría otorgar alcance sobre
             * alguien que sólo existe en otra organización, y eso abriría el
             * expediente de una persona ajena a este tenant.
             */
            PersonaRolAlcance::TIPO_PERSONA => (int) Persona::query()
                ->whereHas('vinculaciones', function ($consulta) use ($organizacionId): void {
                    $consulta->withoutGlobalScopes()
                        ->where('organizacion_id', $organizacionId)
                        ->where('estado', 'activa');
                })
                ->findOrFail($alcanceId)->id,

            default => throw new InvalidArgumentException("Tipo de alcance desconocido: {$tipo}."),
        };
    }
}
