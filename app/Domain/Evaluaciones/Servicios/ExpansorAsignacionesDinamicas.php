<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Eventos\PersonaInscritaEnAgrupacion;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use Illuminate\Support\Carbon;

/**
 * Escucha las altas de membresía y mete a la persona en las asignaciones
 * DINÁMICAS abiertas de su agrupación.
 *
 * Es lo que hace que un alumno que llega en octubre no se quede fuera del
 * tamizaje anual. Y lo que NO ocurre en una asignación snapshot, donde el
 * padrón se congeló a propósito porque la campaña tiene fecha de corte.
 */
class ExpansorAsignacionesDinamicas
{
    public function __construct(private readonly CreadorAsignaciones $creador) {}

    public function handle(PersonaInscritaEnAgrupacion $evento): void
    {
        $miembro = $evento->miembro;

        // Sólo evaluados: un titular responsable del grupo no es sujeto de la
        // evaluación.
        if ($miembro->rol_en_agrupacion !== 'evaluado' || ! $miembro->estaVigente()) {
            return;
        }

        $ahora = Carbon::now();

        $asignaciones = Asignacion::query()
            ->withoutGlobalScopes()
            ->where('agrupacion_id', $miembro->agrupacion_id)
            ->where('incluir_nuevos_miembros', true)
            ->where('estado', 'activa')
            ->where('ventana_fin', '>=', $ahora)
            ->get();

        foreach ($asignaciones as $asignacion) {
            /*
             * Se pasa por agregarDestinatario y no por expandirDinamica: aquí
             * ya sabemos quién entró, y recorrer todo el grupo por cada alta
             * haría que inscribir a treinta alumnos costara treinta barridos.
             */
            $this->creador->agregarDestinatario($asignacion, $miembro->persona);
        }
    }
}
