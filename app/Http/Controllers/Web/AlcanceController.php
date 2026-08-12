<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Accesos\Servicios\AsignadorRoles;
use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaAlcanceRequest;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles con alcance y vigencia: quién puede qué, y sobre quién.
 */
class AlcanceController extends Controller
{
    public function __construct(
        private readonly AsignadorRoles $asignador,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): Response
    {
        $organizacionId = (int) $this->contexto->id();

        $alcances = PersonaRolAlcance::query()
            ->with(['persona', 'rol'])
            ->orderByDesc('id')
            ->paginate(25);

        return Inertia::render('Accesos/Alcances', [
            'alcances' => $alcances->through(fn (PersonaRolAlcance $alcance): array => [
                'id' => $alcance->id,
                'persona' => $alcance->persona->nombreCompleto(),
                'persona_uuid' => $alcance->persona->uuid,
                'rol' => $alcance->rol?->name,
                'alcance_tipo' => $alcance->alcance_tipo,
                'alcance_id' => $alcance->alcance_id,
                'vigencia_inicio' => $alcance->vigencia_inicio->toDateString(),
                'vigencia_fin' => $alcance->vigencia_fin?->toDateString(),
                'vigente' => $alcance->estaVigente(),
            ]),

            'roles' => Rol::query()
                ->where('organizacion_id', $organizacionId)
                ->orderBy('name')
                ->get(['id', 'name']),

            'unidades' => Unidad::query()->orderBy('nombre')->get(['id', 'nombre']),
            'agrupaciones' => Agrupacion::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(GuardaAlcanceRequest $peticion): RedirectResponse
    {
        $validado = $peticion->validated();

        $persona = Persona::query()->where('uuid', $validado['persona_uuid'])->firstOrFail();

        // El rol se busca acotado a la organización activa: mandar el id de un
        // rol de otro tenant no debe concederlo aquí. AsignadorRoles lo vuelve
        // a comprobar, porque también se le llama desde comandos.
        $rol = Rol::query()
            ->where('organizacion_id', $this->contexto->id())
            ->findOrFail($validado['rol_id']);

        $this->asignador->asignar(
            persona: $persona,
            rol: $rol,
            alcanceTipo: $validado['alcance_tipo'],
            alcanceId: isset($validado['alcance_id']) ? (int) $validado['alcance_id'] : null,
            vigenciaInicio: $validado['vigencia_inicio'] ?? null,
            vigenciaFin: $validado['vigencia_fin'] ?? null,
            otorgadoPor: $peticion->user()?->persona,
        );

        return back(303)->with('exito', 'Rol asignado.');
    }

    public function destroy(PersonaRolAlcance $alcance): RedirectResponse
    {
        $this->asignador->retirar($alcance);

        return back(303)->with('exito', 'Alcance retirado.');
    }
}
