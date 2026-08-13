<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Servicios;

use App\Domain\Expedientes\Excepciones\CapturaNoPermitida;
use App\Domain\Expedientes\Modelos\Expediente;
use App\Domain\Expedientes\Modelos\ExpedienteCampo;
use App\Domain\Expedientes\Modelos\ExpedienteValor;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Captura y validación de valores del expediente.
 *
 * La regla que gobierna todo: CORREGIR NO PISA, VERSIONA. El vigente es la
 * mayor versión validada; las anteriores se conservan. Es lo que hace posible
 * la rectificación ARCO sin destruir el dato previo (Doc 06 §3) y lo que
 * permite ver que algo cambió, cuándo y quién lo validó.
 */
class CapturaExpediente
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * El expediente de la persona, creándolo si es su primera vez.
     *
     * `firstOrCreate` y no `create`: el expediente nace solo la primera vez que
     * alguien captura algo, no en el alta de la persona. Crearlo en el alta
     * llenaría la tabla de expedientes vacíos de gente tamizada una vez.
     */
    public function expedienteDe(Persona $persona): Expediente
    {
        return Expediente::query()->firstOrCreate(
            ['persona_id' => $persona->id],
            ['estado' => 'activo']
        );
    }

    /**
     * Captura un valor. Devuelve la fila nueva, no la anterior.
     *
     * @throws CapturaNoPermitida
     */
    public function capturar(
        Persona $sujeto,
        ExpedienteCampo $campo,
        mixed $valor,
        Persona $quienCaptura,
    ): ExpedienteValor {
        $rol = $this->rolDeQuienCaptura($sujeto, $quienCaptura);

        $this->exigirQuePuedaLlenarlo($campo, $rol);

        $expediente = $this->expedienteDe($sujeto);

        return DB::transaction(function () use ($expediente, $campo, $valor, $quienCaptura, $rol): ExpedienteValor {
            /*
             * La versión sale del MÁXIMO existente, no de un contador
             * guardado: un contador se desincroniza en cuanto haya dos
             * capturas simultáneas, y el único de (expediente, campo, versión)
             * las detendría con un choque incomprensible.
             */
            $siguiente = 1 + (int) ExpedienteValor::query()
                ->where('expediente_id', $expediente->id)
                ->where('campo_id', $campo->id)
                ->max('version');

            $naceValidado = $campo->loCapturadoNaceValidado($rol);

            return ExpedienteValor::query()->create([
                'expediente_id' => $expediente->id,
                'campo_id' => $campo->id,
                'organizacion_id_contexto' => $this->contexto->id(),
                $campo->columnaDeValor() => $this->normalizar($campo, $valor),
                'capturado_por' => $quienCaptura->id,
                'estado' => $naceValidado ? 'validado' : 'pendiente_validacion',
                'validado_por' => $naceValidado ? $quienCaptura->id : null,
                'version' => $siguiente,
            ]);
        });
    }

    /**
     * Da por bueno lo que capturó el titular o el tutor.
     */
    public function validar(ExpedienteValor $valor, Persona $validador): ExpedienteValor
    {
        $valor->update([
            'estado' => 'validado',
            'validado_por' => $validador->id,
        ]);

        return $valor;
    }

    public function rechazar(ExpedienteValor $valor, Persona $validador): ExpedienteValor
    {
        /*
         * Rechazar NO borra: la versión rechazada se conserva. Si se borrara,
         * no habría forma de saber que alguien capturó un dato equivocado ni
         * de mostrarle qué se le rechazó.
         */
        $valor->update([
            'estado' => 'rechazado',
            'validado_por' => $validador->id,
        ]);

        return $valor;
    }

    /**
     * Los valores VIGENTES del expediente: por campo, la mayor versión
     * validada.
     *
     * @return Collection<int, ExpedienteValor> indexada por campo_id
     */
    public function valoresVigentes(Expediente $expediente): Collection
    {
        /** @var Collection<int, ExpedienteValor> $valores */
        $valores = ExpedienteValor::query()
            ->where('expediente_id', $expediente->id)
            ->validados()
            ->with(['campo', 'opcion'])
            ->orderBy('campo_id')
            ->orderBy('version')
            ->get();

        // El orderBy por versión hace que el último de cada campo sea el mayor,
        // y keyBy se queda con el último. Una subconsulta con MAX(version) por
        // campo cuesta más y devuelve lo mismo.
        return $valores->keyBy('campo_id');
    }

    /**
     * @return Collection<int, ExpedienteValor>
     */
    public function pendientesDeValidar(Expediente $expediente): Collection
    {
        /** @var Collection<int, ExpedienteValor> */
        return ExpedienteValor::query()
            ->where('expediente_id', $expediente->id)
            ->where('estado', 'pendiente_validacion')
            ->with(['campo', 'capturadoPor'])
            ->get();
    }

    /**
     * Con qué sombrero está capturando esta persona.
     */
    private function rolDeQuienCaptura(Persona $sujeto, Persona $quienCaptura): string
    {
        if ($sujeto->id === $quienCaptura->id) {
            return 'titular';
        }

        $esTutor = Tutoria::query()
            ->where('tutor_persona_id', $quienCaptura->id)
            ->where('menor_persona_id', $sujeto->id)
            ->vigentes()
            ->exists();

        return $esTutor ? 'tutor' : 'profesional';
    }

    /**
     * @throws CapturaNoPermitida
     */
    private function exigirQuePuedaLlenarlo(ExpedienteCampo $campo, string $rol): void
    {
        if (! $campo->activo) {
            throw CapturaNoPermitida::porCampoInactivo($campo->clave);
        }

        /*
         * `admin` y `profesional` pueden llenar lo que el titular llena; al
         * revés no. Un antecedente médico marcado `profesional` no lo captura
         * el titular desde su portal — no porque no sepa, sino porque quien
         * responde de ese dato ante la organización es un profesional.
         */
        $permitidos = match ($campo->quien_puede_llenar) {
            'titular' => ['titular', 'tutor', 'profesional', 'admin'],
            'tutor' => ['tutor', 'profesional', 'admin'],
            'profesional' => ['profesional', 'admin'],
            'admin' => ['admin'],
            default => [],
        };

        if (! in_array($rol, $permitidos, true)) {
            throw CapturaNoPermitida::porRol($campo->etiqueta, $campo->quien_puede_llenar);
        }
    }

    private function normalizar(ExpedienteCampo $campo, mixed $valor): mixed
    {
        return match ($campo->tipo_dato) {
            'numero' => $valor === null || $valor === '' ? null : (float) $valor,
            'fecha' => $valor === null || $valor === '' ? null : (string) $valor,
            'catalogo', 'archivo' => $valor === null || $valor === '' ? null : (int) $valor,
            'booleano' => $valor === null ? null : ($valor ? '1' : '0'),
            default => $valor === null ? null : (string) $valor,
        };
    }
}
