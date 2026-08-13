<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Excepciones\ResultadoNoDisponible;
use App\Domain\Interpretacion\Modelos\PerfilPuesto;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Servicios\ComparadorPuesto;
use App\Domain\Interpretacion\Servicios\DetectorCambioSignificativo;
use App\Domain\Interpretacion\Servicios\VistaResultados;
use App\Domain\Personas\Modelos\Persona;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doc 07 §6 — resultados.
 *
 * La AUDIENCIA se deriva del rol de quien pregunta y NUNCA llega por
 * parámetro. Si el evaluado pudiera pedir la audiencia `profesional`, el texto
 * cuidado que se escribió para él dejaría de servir para nada.
 */
class ResultadoController extends ApiV1Controller
{
    public function __construct(
        private readonly VistaResultados $vista,
        private readonly ComparadorPuesto $comparador,
        private readonly DetectorCambioSignificativo $cambios,
    ) {}

    public function show(Request $peticion, Aplicacion $aplicacion): JsonResponse
    {
        $actor = $this->actor($peticion);

        try {
            return response()->json($this->vista->para($actor, $aplicacion));
        } catch (ResultadoNoDisponible $error) {
            /*
             * 404 y no 403: un 403 confirmaría que la aplicación existe y que
             * esa persona fue evaluada aquí, que es justo lo que no se puede
             * filtrar (Doc 07 §8.1).
             */
            return $this->noEncontrado();
        }
    }

    /**
     * Perfil longitudinal: la "ficha de hospital" del Doc 01.
     */
    public function longitudinal(Request $peticion, Persona $persona): JsonResponse
    {
        $actor = $this->actor($peticion);

        $decision = app(\App\Domain\Accesos\Servicios\AccesoService::class)->autorizar(
            actor: $actor,
            accion: 'resultados.ver_detalle',
            sujeto: $persona,
        );

        if (! $decision->permitido) {
            return $this->noEncontrado();
        }

        $serie = ResultadoNormalizado::query()
            ->where('persona_id', $persona->id)
            ->when($peticion->query('constructo'), fn ($consulta, $constructo) => $consulta
                ->where('constructo', $constructo))
            ->when($peticion->query('dominio'), fn ($consulta, $dominio) => $consulta
                ->where('dominio_id', $dominio))
            ->when($peticion->query('desde'), fn ($consulta, $desde) => $consulta
                ->where('fecha', '>=', $desde))
            ->when($peticion->query('hasta'), fn ($consulta, $hasta) => $consulta
                ->where('fecha', '<=', $hasta))
            ->orderBy('constructo')
            ->orderBy('fecha')
            ->get();

        $porConstructo = [];

        foreach ($serie->groupBy('constructo') as $constructo => $puntos) {
            $porConstructo[] = [
                'constructo' => $constructo,
                'puntos' => $puntos->map(static fn (ResultadoNormalizado $punto): array => [
                    'fecha' => $punto->fecha->toDateString(),
                    'tipo_norma' => $punto->tipo_norma,
                    'valor' => $punto->valor,
                    'bandera' => $punto->bandera,
                ])->values()->all(),

                // Los saltos que salen del error de medida. Es lo que hace que
                // la gráfica sirva para algo más que verse bonita.
                'cambios' => $this->cambios->serieDe($persona->id, (string) $constructo),
            ];
        }

        return response()->json(['persona_uuid' => $persona->uuid, 'series' => $porConstructo]);
    }

    /**
     * Ajuste candidato ↔ puesto.
     */
    public function compararPuesto(
        Request $peticion,
        Persona $persona,
        PerfilPuesto $perfilPuesto,
    ): JsonResponse {
        $actor = $this->actor($peticion);

        $decision = app(\App\Domain\Accesos\Servicios\AccesoService::class)->autorizar(
            actor: $actor,
            accion: 'resultados.ver_detalle',
            sujeto: $persona,
        );

        if (! $decision->permitido) {
            return $this->noEncontrado();
        }

        $aplicaciones = Aplicacion::query()
            ->where('persona_id', $persona->id)
            ->where('estado', 'completada')
            ->get()
            ->all();

        return response()->json([
            'persona_uuid' => $persona->uuid,
            'perfil_puesto' => $perfilPuesto->nombre,
            ...$this->comparador->comparar($perfilPuesto, $aplicaciones),
        ]);
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }

    private function noEncontrado(): JsonResponse
    {
        return response()->json([
            'type' => 'https://mentia.mx/problemas/no-encontrado',
            'title' => 'No encontrado',
            'status' => 404,
            'detail' => 'No existe ese recurso o no está disponible.',
        ], 404, ['Content-Type' => 'application/problem+json']);
    }
}
