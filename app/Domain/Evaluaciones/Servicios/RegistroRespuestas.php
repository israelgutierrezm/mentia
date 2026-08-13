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
 * 4. **Corrección, no acumulación.** Cambiar de respuesta ACTUALIZA la fila; no
 *    agrega otra. La gente cambia de opción todo el tiempo, y el único de la
 *    base es `(aplicacion, reactivo, opcion)`: sin esto, quien marca "Nunca" y
 *    se corrige a "Siempre" deja las dos filas y el pipeline suma un reactivo
 *    dos veces (Doc 03 §M7, que ya encarga esta comprobación al servicio).
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
        $corregidas = 0;

        /** @var array<int, bool> $limpiados */
        $limpiados = [];

        DB::transaction(function () use ($aplicacion, $lote, &$aceptadas, &$duplicadas, &$desconocidas, &$corregidas, &$limpiados): void {
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

                if ($this->esDeVariasFilas($entrada)) {
                    $reemplazo = $this->prepararReemplazo($aplicacion, $reactivo, $entrada, $limpiados);

                    if ($reemplazo === false) {
                        $duplicadas[] = $uuidCliente;

                        continue;
                    }

                    if ($reemplazo === true) {
                        $corregidas++;
                    }

                    $aceptadas[] = $this->crear($aplicacion, $reactivo, $entrada, $uuidCliente);

                    continue;
                }

                $anterior = $this->vigenteDe($aplicacion, $reactivo);
                $guardada = $this->guardar($aplicacion, $reactivo, $entrada, $uuidCliente, $anterior);

                if ($guardada === null) {
                    // Llegó una respuesta VIEJA después de una nueva. No pisa.
                    $duplicadas[] = $uuidCliente;

                    continue;
                }

                if ($anterior !== null) {
                    $corregidas++;
                }

                $aceptadas[] = $guardada;
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
            'corregidas' => $corregidas,
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
     * Guarda o CORRIGE.
     *
     * Devuelve null cuando la entrada no debe aplicarse porque ya hay una
     * respuesta más reciente para ese reactivo.
     *
     * @param  array<string, mixed>  $entrada
     */
    private function guardar(
        Aplicacion $aplicacion,
        Reactivo $reactivo,
        array $entrada,
        string $uuidCliente,
        ?Respuesta $anterior,
    ): ?Respuesta {
        $opcion = $this->opcionDe($reactivo, $entrada['opcion_codigo'] ?? null);

        $respondidaEn = isset($entrada['respondida_en'])
            ? Carbon::parse((string) $entrada['respondida_en'])
            : Carbon::now();

        $atributos = [
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
        ];

        if ($anterior === null) {
            return Respuesta::query()->create($atributos);
        }

        /*
         * GANA LA MÁS RECIENTE, por `respondida_en` y no por orden de llegada.
         * En modo offline los lotes se sincronizan desordenados: sin esto, un
         * paquete viejo que llega tarde deshace una corrección posterior, y el
         * expediente queda con la respuesta que la persona ya había cambiado.
         */
        if ($anterior->respondida_en->greaterThan($respondidaEn)) {
            return null;
        }

        $anterior->update($atributos);

        return $anterior;
    }

    /**
     * La respuesta vigente de un reactivo de UNA SOLA fila.
     */
    private function vigenteDe(Aplicacion $aplicacion, Reactivo $reactivo): ?Respuesta
    {
        return Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->where('reactivo_id', $reactivo->id)
            ->first();
    }

    /**
     * ¿Este reactivo ocupa varias filas?
     *
     * Ranking e ipsativos: el orden completo o el par más/menos son UNA
     * respuesta repartida en varias filas.
     *
     * @param  array<string, mixed>  $entrada
     */
    private function esDeVariasFilas(array $entrada): bool
    {
        return ($entrada['posicion_ranking'] ?? null) !== null
            || ($entrada['rol_ipsativo'] ?? null) !== null;
    }

    /**
     * Deja el terreno limpio para un ranking o un ipsativo.
     *
     * En estos, la respuesta es el CONJUNTO: corregir fila por fila no
     * funciona. Si alguien cambia cuál opción es la que "más" lo describe,
     * actualizar sólo la nueva dejaría la anterior marcada y el cuadro tendría
     * dos «más»; y reordenar un ranking chocaría contra el único de la base a
     * medio camino, cuando dos filas quieren la misma opción. Se borra el
     * conjunto anterior una vez por lote y se vuelve a escribir completo.
     *
     * Devuelve `false` si el lote es más viejo que lo ya guardado —no se
     * aplica—, `true` si hubo algo que reemplazar y `null` si no había nada.
     *
     * @param  array<string, mixed>  $entrada
     * @param  array<int, bool>  $limpiados
     */
    private function prepararReemplazo(
        Aplicacion $aplicacion,
        Reactivo $reactivo,
        array $entrada,
        array &$limpiados,
    ): ?bool {
        if (isset($limpiados[$reactivo->id])) {
            return null;
        }

        $previas = Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->where('reactivo_id', $reactivo->id)
            ->get();

        if ($previas->isEmpty()) {
            $limpiados[$reactivo->id] = true;

            return null;
        }

        $respondidaEn = isset($entrada['respondida_en'])
            ? Carbon::parse((string) $entrada['respondida_en'])
            : Carbon::now();

        // Gana la más reciente, igual que en los de una sola fila.
        $masNueva = $previas->max(
            static fn (Respuesta $respuesta): Carbon => $respuesta->respondida_en
        );

        if ($masNueva instanceof Carbon && $masNueva->greaterThan($respondidaEn)) {
            return false;
        }

        Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->where('reactivo_id', $reactivo->id)
            ->delete();

        $limpiados[$reactivo->id] = true;

        return true;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    private function crear(
        Aplicacion $aplicacion,
        Reactivo $reactivo,
        array $entrada,
        string $uuidCliente,
    ): Respuesta {
        $respuesta = $this->guardar($aplicacion, $reactivo, $entrada, $uuidCliente, null);

        // `guardar()` sólo devuelve null cuando hay una anterior que desempatar,
        // y aquí se le pasa null a propósito.
        assert($respuesta instanceof Respuesta);

        return $respuesta;
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
