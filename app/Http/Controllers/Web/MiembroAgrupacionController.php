<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Servicios\GestorAgrupaciones;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MiembroAgrupacionController extends Controller
{
    public function __construct(private readonly GestorAgrupaciones $gestor) {}

    public function store(Request $peticion, Agrupacion $agrupacion): RedirectResponse
    {
        $validado = $peticion->validate([
            'persona_uuid' => ['required', 'uuid'],
            'rol_en_agrupacion' => ['sometimes', 'in:evaluado,titular_responsable'],
        ]);

        /*
         * La persona tiene que estar VINCULADA a la organización activa.
         *
         * Sin esta comprobación, mandar el uuid de cualquier persona de la
         * plataforma la metería al grupo de este tenant: `personas` es global
         * y no tiene global scope que lo impida. Ésta es la puerta.
         */
        $persona = Persona::query()
            ->where('uuid', $validado['persona_uuid'])
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->firstOrFail();

        $this->gestor->inscribir(
            $agrupacion,
            $persona,
            $validado['rol_en_agrupacion'] ?? 'evaluado'
        );

        return back(303)->with('exito', 'Persona inscrita.');
    }

    public function destroy(Agrupacion $agrupacion, AgrupacionMiembro $miembro): RedirectResponse
    {
        // La membresía tiene que ser de ESTA agrupación: sin la comprobación,
        // un id de miembro ajeno daría de baja a alguien de otro grupo —y
        // agrupacion_miembros no lleva organizacion_id propio—.
        abort_unless($miembro->agrupacion_id === $agrupacion->id, 404);

        $this->gestor->darDeBaja($miembro);

        return back(303)->with('exito', 'Persona dada de baja del grupo.');
    }
}
