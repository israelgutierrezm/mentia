<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Personas\Datos\DatosPersona;
use App\Domain\Personas\Excepciones\IdentidadEnConflicto;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Servicios\RegistroPersonas;
use App\Http\Requests\GuardaPersonaRequest;
use App\Http\Resources\PersonaResource;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PersonaController extends ApiV1Controller
{
    public function __construct(
        private readonly RegistroPersonas $registro,
        private readonly AccesoService $accesos,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(Request $peticion): AnonymousResourceCollection
    {
        /*
         * Se pagina sobre las VINCULACIONES, no sobre personas: `personas` es
         * global y sin scope, así que consultarla derecho devolvería el padrón
         * de toda la plataforma.
         */
        $vinculos = OrganizacionPersona::query()
            ->activas()
            ->with('persona')
            ->orderBy('id')
            ->cursorPaginate($this->limite((int) $peticion->query('limit', '0')));

        return PersonaResource::collection(
            $vinculos->through(fn (OrganizacionPersona $vinculo): Persona => $vinculo->persona)
        );
    }

    public function store(GuardaPersonaRequest $peticion): JsonResponse
    {
        $organizacion = $this->contexto->organizacion();
        abort_if($organizacion === null, 403);

        try {
            $vinculo = $this->registro->altaEnOrganizacion(
                DatosPersona::desdeValidados($peticion->validated()),
                $organizacion
            );
        } catch (IdentidadEnConflicto $conflicto) {
            throw ValidationException::withMessages(['curp' => $conflicto->getMessage()]);
        }

        return (new PersonaResource($vinculo->persona))
            ->additional(['origen_alta' => $vinculo->origen_alta])
            ->response()
            ->setStatusCode($vinculo->origen_alta === 'creada' ? 201 : 200);
    }

    public function show(Request $peticion, Persona $persona): JsonResponse
    {
        $actor = $peticion->user()?->persona;
        abort_if($actor === null, 403);

        $this->accesos->autorizar($actor, 'personas.ver', $persona)->oFallar();

        return (new PersonaResource($persona))
            ->additional(['curp' => $persona->curp])
            ->response();
    }
}
