<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Alertas\Servicios\AlertaService;
use App\Domain\Catalogo\Modelos\OpcionReactivo;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Evaluaciones\Excepciones\AplicacionInvalida;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\AplicacionBloque;
use App\Domain\Evaluaciones\Modelos\Respuesta;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recepción de respuestas por LOTES, idempotente.
 *
 * TRES COSAS QUE PASAN AQUÍ Y NO EN OTRO LADO:
 *
 * 1. **Idempotencia por `uuid_cliente`.** El cliente genera el uuid antes de
 *    mandar, así que reintentar un lote que se perdió en la red no duplica
 *    nada. Es lo que hace posible el modo offline de la V3.
 * 2. **Marcado de tardías.** Una respuesta que llega con su bloque ya expirado
 *    NO se rechaza —perderla sería peor— pero se marca. Qué hacer con ellas lo
 *    decide el pipeline, no esto.
 * 3. **Centinelas SÍNCRONOS.** Antes de devolver el lote se evalúan sus
 *    centinelas. Es la diferencia entre enterarse de una ideación suicida
 *    ahora, con la persona todavía en la pantalla, o mañana cuando la cola
 *    termine de calificar (Doc 05 §3).
 */
class RegistroRespuestas
{
    public function __construct(
        private readonly MotorAplicacion $motor,
        private readonly EvaluadorCentinelas $centinelas,
        private readonly AlertaService $alertas,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lote
     * @return array<string, mixed>
     *
     * @throws AplicacionInvalida
     */
    public function recibir(Aplicacion $aplicacion, array $lote): array
    {
        if (! $aplicacion->admiteRespuestas()) {
            throw AplicacionInvalida::porNoAdmitirRespuestas($aplicacion->estado);
        }

        $aceptadas = [];
        $duplicadas = [];
        $desconocidas = [];

        DB::transaction(function () use ($aplicacion, $lote, &$aceptadas, &$duplicadas, &$desconocidas): void {
            foreach ($lote as $entrada) {
                $uuidCliente = (string) ($entrada['uuid_cliente'] ?? '');

                if ($uuidCliente === '') {
                    $desconocidas[] = ['motivo' => 'Falta uuid_cliente.'];

                    continue;
                }

                /*
                 * La comprobación de duplicado va DENTRO de la transacción y
                 * el único de la base la respalda: entre el SELECT y el INSERT
                 * puede colarse otro lote idéntico, y el índice es lo único
                 * que lo impide de verdad.
                 */
                $yaEsta = Respuesta::query()->where('uuid_cliente', $uuidCliente)->exists();

                if ($yaEsta) {
                    $duplicadas[] = $uuidCliente;

                    continue;
                }

                $reactivo = $this->reactivoDe($aplicacion, (string) ($entrada['reactivo_codigo'] ?? ''));

                if ($reactivo === null) {
                    $desconocidas[] = [
                        'uuid_cliente' => $uuidCliente,
                        'motivo' => 'El reactivo no pertenece a este instrumento.',
                    ];

                    continue;
                }

                $aceptadas[] = $this->guardar($aplicacion, $reactivo, $entrada, $uuidCliente);
            }
        });

        // ── Centinelas del lote, SÍNCRONO ────────────────────────────────
        $disparos = $this->centinelas->evaluar($aceptadas);

        foreach ($disparos as $disparo) {
            $this->alertas->porCentinela($aplicacion, $disparo);
        }

        $bloqueActual = $this->motor->bloqueActual($aplicacion);

        return [
            'aceptadas' => count($aceptadas),
            'duplicadas' => count($duplicadas),
            'rechazadas' => $desconocidas,
            'tardias' => count(array_filter(
                $aceptadas,
                static fn (Respuesta $respuesta): bool => $respuesta->tardia
            )),

            /*
             * El cronómetro viaja en CADA respuesta al lote. Es lo que permite
             * que el cliente corrija su reloj sin tener que preguntar aparte, y
             * lo que hace que el server-side sea la única fuente.
             */
            'cronometro' => $bloqueActual !== null
                ? $this->motor->cronometroDe($bloqueActual)
                : null,

            'alertas_generadas' => count($disparos),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    private function guardar(
        Aplicacion $aplicacion,
        Reactivo $reactivo,
        array $entrada,
        string $uuidCliente,
    ): Respuesta {
        $opcion = $this->opcionDe($reactivo, $entrada['opcion_codigo'] ?? null);

        $respondidaEn = isset($entrada['respondida_en'])
            ? Carbon::parse((string) $entrada['respondida_en'])
            : Carbon::now();

        return Respuesta::query()->create([
            'aplicacion_id' => $aplicacion->id,
            'reactivo_id' => $reactivo->id,
            'opcion_id' => $opcion?->id,
            'valor_numerico' => $entrada['valor_numerico'] ?? null,
            'valor_texto' => $entrada['valor_texto'] ?? null,
            'media_id' => $entrada['media_id'] ?? null,
            'rol_ipsativo' => $entrada['rol_ipsativo'] ?? null,
            'posicion_ranking' => $entrada['posicion_ranking'] ?? null,
            'uuid_cliente' => $uuidCliente,
            'tiempo_respuesta_ms' => $entrada['tiempo_respuesta_ms'] ?? null,
            'respondida_en' => $respondidaEn,
            'origen' => $entrada['origen'] ?? 'online',
            'tardia' => $this->esTardia($aplicacion, $reactivo, $respondidaEn),
        ]);
    }

    /**
     * ¿Llegó con su bloque ya expirado?
     *
     * Se compara contra el momento en que se RESPONDIÓ, no contra ahora: en
     * modo offline una respuesta puede sincronizarse días después y seguir
     * habiendo sido contestada dentro de tiempo.
     */
    private function esTardia(Aplicacion $aplicacion, Reactivo $reactivo, Carbon $respondidaEn): bool
    {
        $estado = AplicacionBloque::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->where('bloque_id', $reactivo->bloque_id)
            ->with('bloque')
            ->first();

        if ($estado === null) {
            return false;
        }

        return $estado->expirado($respondidaEn);
    }

    private function reactivoDe(Aplicacion $aplicacion, string $codigo): ?Reactivo
    {
        if ($codigo === '') {
            return null;
        }

        return Reactivo::query()
            ->where('version_instrumento_id', $aplicacion->version_instrumento_id)
            ->where('codigo', $codigo)
            ->deContenidoVisiblePara($this->contexto->id())
            ->first();
    }

    private function opcionDe(Reactivo $reactivo, mixed $codigo): ?OpcionReactivo
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        return OpcionReactivo::query()
            ->where('reactivo_id', $reactivo->id)
            ->where('codigo', (string) $codigo)
            ->first();
    }
}
