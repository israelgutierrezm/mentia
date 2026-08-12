<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Personas\Datos\DatosPersona;
use App\Domain\Personas\Excepciones\IdentidadEnConflicto;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Servicios\RegistroPersonas;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaPersonaRequest;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PersonaController extends Controller
{
    public function __construct(
        private readonly RegistroPersonas $registro,
        private readonly AccesoService $accesos,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function index(): Response
    {
        /*
         * Se listan las VINCULACIONES, no las personas.
         *
         * `personas` es global y no lleva global scope: consultarla
         * directamente devolvería el padrón de toda la plataforma. El
         * acotamiento entra por `organizacion_personas`, que sí es de tenant.
         */
        $vinculos = OrganizacionPersona::query()
            ->activas()
            ->with('persona')
            ->paginate(25);

        return Inertia::render('Personas/Index', [
            'vinculos' => $vinculos->through(fn (OrganizacionPersona $vinculo): array => [
                'uuid' => $vinculo->persona->uuid,
                'nombre_completo' => $vinculo->persona->nombreCompleto(),
                'fecha_nacimiento' => $vinculo->persona->fecha_nacimiento->toDateString(),
                'matricula' => $vinculo->matricula_o_num_empleado,
                'origen_alta' => $vinculo->origen_alta,
            ]),
        ]);
    }

    public function store(GuardaPersonaRequest $peticion): RedirectResponse
    {
        $organizacion = $this->contexto->organizacion();
        abort_if($organizacion === null, 403);

        try {
            $vinculo = $this->registro->altaEnOrganizacion(
                DatosPersona::desdeValidados($peticion->validated()),
                $organizacion
            );
        } catch (IdentidadEnConflicto $conflicto) {
            /*
             * Se traduce a error de validación sobre `curp` para que el
             * mensaje aparezca junto al campo. Dejarlo subir como 500 le
             * diría al capturista "algo salió mal" cuando lo que pasó es que
             * tecleó mal una fecha.
             */
            throw ValidationException::withMessages([
                'curp' => $conflicto->getMessage(),
            ]);
        }

        return back(303)->with(
            'exito',
            $vinculo->origen_alta === 'vinculada'
                ? 'La persona ya existía en la plataforma y quedó vinculada a esta organización.'
                : 'Persona registrada.'
        );
    }

    public function show(Persona $persona): Response
    {
        $actor = request()->user()?->persona;
        abort_if($actor === null, 403);

        // Ver la ficha de una persona ES un acceso a datos de persona: pasa
        // por AccesoService y deja bitácora, autorice o niegue.
        $this->accesos->autorizar($actor, 'personas.ver', $persona)->oFallar();

        return Inertia::render('Personas/Ficha', [
            'persona' => [
                'uuid' => $persona->uuid,
                'nombre_completo' => $persona->nombreCompleto(),
                'curp' => $persona->curp,
                'fecha_nacimiento' => $persona->fecha_nacimiento->toDateString(),
                'sexo_registral' => $persona->sexo_registral,
                'verificacion_identidad' => $persona->verificacion_identidad,
                'es_menor' => $persona->esMenorDeEdad(),
            ],
        ]);
    }
}
