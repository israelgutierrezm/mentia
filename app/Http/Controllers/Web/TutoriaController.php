<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Personas\Excepciones\TutoriaInvalida;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Domain\Personas\Servicios\GestorTutorias;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardaTutoriaRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TutoriaController extends Controller
{
    public function __construct(private readonly GestorTutorias $gestor) {}

    public function index(): Response
    {
        return Inertia::render('Personas/Tutorias', [
            'tutorias' => $this->gestor->listar()->map(fn (Tutoria $tutoria): array => [
                'id' => $tutoria->id,
                'tutor' => $tutoria->tutor->nombreCompleto(),
                'menor' => $tutoria->menor->nombreCompleto(),
                'menor_uuid' => $tutoria->menor->uuid,
                'parentesco' => $tutoria->parentesco,
                'estado' => $tutoria->estado,
                'vigencia_inicio' => $tutoria->vigencia_inicio->toDateString(),
                'vigencia_fin' => $tutoria->vigencia_fin?->toDateString(),
                'vigente' => $tutoria->estaVigente(),
            ])->all(),
        ]);
    }

    public function store(GuardaTutoriaRequest $peticion): RedirectResponse
    {
        $validado = $peticion->validated();

        try {
            $this->gestor->registrar(
                $this->personaPorUuid($validado['tutor_uuid']),
                $this->personaPorUuid($validado['menor_uuid']),
                $validado['parentesco'],
                $validado['vigencia_inicio'] ?? null,
                $validado['vigencia_fin'] ?? null,
            );
        } catch (TutoriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with(
            'exito',
            'Tutoría registrada. Queda PENDIENTE de validación: todavía no da acceso.'
        );
    }

    public function validar(Request $peticion, Tutoria $tutoria): RedirectResponse
    {
        $usuario = $peticion->user();

        // La ruta ya lleva `can:tutorias.validar`; esto es la segunda vuelta.
        // Quién acredita queda con nombre en la fila, así que hace falta la
        // persona, no sólo la cuenta.
        abort_unless($usuario instanceof User, 403);
        abort_unless($usuario->can('tutorias.validar'), 403);

        try {
            $this->gestor->validar($tutoria, $usuario->persona);
        } catch (TutoriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Tutoría acreditada. A partir de ahora da acceso.');
    }

    public function revocar(Request $peticion, Tutoria $tutoria): RedirectResponse
    {
        abort_unless($peticion->user() instanceof User, 403);
        abort_unless($peticion->user()->can('tutorias.validar'), 403);

        try {
            $this->gestor->revocar($tutoria);
        } catch (TutoriaInvalida $error) {
            return back(303)->with('error', $error->getMessage());
        }

        return back(303)->with('exito', 'Tutoría revocada. El acceso queda cortado.');
    }

    /**
     * La persona tiene que estar VINCULADA a la organización activa: `personas`
     * es global y sin esta puerta se podría acreditar una tutoría sobre alguien
     * que sólo existe en otro tenant.
     */
    private function personaPorUuid(string $uuid): Persona
    {
        return Persona::query()
            ->where('uuid', $uuid)
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->firstOrFail();
    }
}
