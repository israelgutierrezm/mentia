<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Catalogo\Modelos\Dominio;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Interpretacion\Excepciones\ResultadoNoDisponible;
use App\Domain\Interpretacion\Modelos\ResultadoNormalizado;
use App\Domain\Interpretacion\Servicios\DetectorCambioSignificativo;
use App\Domain\Interpretacion\Servicios\VistaResultados;
use App\Domain\Personas\Modelos\Persona;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las vistas de resultados (Doc 08, Fase 8).
 *
 * Dos pantallas y una sola regla que las gobierna: **la audiencia se deriva del
 * rol de quien mira**. La misma URL le enseña a la psicóloga el perfil técnico
 * y a la madre el texto que se escribió para ella, y no hay parámetro que lo
 * cambie.
 */
class ResultadoController extends Controller
{
    public function __construct(
        private readonly VistaResultados $vista,
        private readonly AccesoService $acceso,
        private readonly DetectorCambioSignificativo $cambios,
    ) {}

    /**
     * Resultado de una aplicación, con gráfica de perfil por escalas.
     */
    public function show(Request $peticion, Aplicacion $aplicacion): Response
    {
        $actor = $this->actor($peticion);

        try {
            $resultado = $this->vista->para($actor, $aplicacion);
        } catch (ResultadoNoDisponible $error) {
            // 404, no 403: un 403 confirmaría que esa persona fue evaluada aquí.
            abort(404);
        }

        return Inertia::render('Resultados/Individual', ['resultado' => $resultado]);
    }

    /**
     * El perfil longitudinal: la "ficha de hospital" del Doc 01.
     *
     * Los dominios son las tarjetas y cada constructo su serie. Es la vista que
     * hace visible la idea rectora del proyecto —ver la evolución de cada
     * "órgano" en el tiempo— y por eso agrupa por dominio y no por instrumento:
     * a nadie le importa con qué prueba se midió la ansiedad en 2023, le importa
     * la ansiedad.
     */
    public function longitudinal(Request $peticion, Persona $persona): Response
    {
        $actor = $this->actor($peticion);

        $decision = $this->acceso->autorizar(
            actor: $actor,
            accion: 'resultados.ver_detalle',
            sujeto: $persona,
        );

        abort_unless($decision->permitido, 404);

        $serie = ResultadoNormalizado::query()
            ->where('persona_id', $persona->id)
            ->orderBy('fecha')
            ->get();

        $dominios = Dominio::query()
            ->whereIn('id', $serie->pluck('dominio_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $tarjetas = [];

        foreach ($serie->groupBy('dominio_id') as $dominioId => $delDominio) {
            $constructos = [];

            foreach ($delDominio->groupBy('constructo') as $constructo => $puntos) {
                $constructos[] = [
                    'constructo' => $constructo,
                    'puntos' => $puntos->map(
                        static fn (ResultadoNormalizado $punto): array => [
                            'fecha' => $punto->fecha->toDateString(),
                            'valor' => $punto->valor,
                            'tipo_norma' => $punto->tipo_norma,
                            'bandera' => $punto->bandera,
                        ]
                    )->values()->all(),
                    'cambios' => $this->cambios->serieDe($persona->id, (string) $constructo),
                ];
            }

            $dominio = $dominios->get($dominioId);

            $tarjetas[] = [
                'dominio' => $dominio === null ? 'Sin dominio' : $dominio->nombre,
                'constructos' => $constructos,
            ];
        }

        return Inertia::render('Resultados/Longitudinal', [
            'persona' => [
                'uuid' => $persona->uuid,
                'nombre' => $persona->nombreCompleto(),
            ],
            'dominios' => $tarjetas,
        ]);
    }

    private function actor(Request $peticion): Persona
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario->persona;
    }
}
