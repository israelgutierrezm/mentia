<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Catalogo\Modelos\Bloque;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\AplicacionInvalida;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\AplicacionBloque;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Jobs\CalificarAplicacion;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El motor de aplicación.
 *
 * DOS REGLAS QUE GOBIERNAN TODO:
 *
 * 1. **El contenido se entrega PARCELADO.** `iniciar()` devuelve la estructura
 *    —cuántos bloques, cuántos reactivos, cuánto tiempo— y NINGÚN enunciado.
 *    Los reactivos se piden bloque por bloque. Un endpoint que devolviera el
 *    instrumento completo sería la forma más cómoda de descargarse una prueba
 *    con copyright (Doc 06 §3).
 * 2. **El cronómetro es SIEMPRE server-side.** Se calcula desde
 *    `aplicacion_bloques.iniciado_en`; el cliente sólo lo muestra. Si el
 *    cliente llevara la cuenta, cambiar la hora del sistema bastaría para
 *    tener el doble de tiempo — y en una prueba de velocidad el tiempo ES el
 *    constructo.
 */
class MotorAplicacion
{
    public function __construct(
        private readonly ContextoOrganizacion $contexto,
        private readonly ResolutorSaltos $saltos,
    ) {}

    /**
     * Arranca (o reanuda) la aplicación de un destinatario.
     *
     * Es IDEMPOTENTE: llamarlo dos veces no crea dos aplicaciones. Quien
     * recarga la pantalla a media prueba tiene que volver donde estaba, no
     * empezar de cero.
     *
     * @throws AplicacionInvalida
     */
    public function iniciar(
        AsignacionDestinatario $destinatario,
        ?VersionInstrumento $version = null,
        string $modalidad = 'en_linea',
        ?string $dispositivo = null,
        ?string $ip = null,
    ): Aplicacion {
        $asignacion = $destinatario->asignacion;

        if (! $asignacion->ventanaAbierta()) {
            throw AplicacionInvalida::porVentanaCerrada();
        }

        $version ??= $this->versionDe($destinatario);

        $existente = Aplicacion::query()
            ->withoutGlobalScopes()
            ->where('asignacion_destinatario_id', $destinatario->id)
            ->where('version_instrumento_id', $version->id)
            ->whereIn('estado', ['iniciada', 'en_pausa'])
            ->first();

        if ($existente !== null) {
            return $this->reanudar($existente);
        }

        $this->exigirQueQuedenIntentos($destinatario, $version);

        return DB::transaction(function () use ($destinatario, $asignacion, $version, $modalidad, $dispositivo, $ip): Aplicacion {
            $anonima = $asignacion->es_anonima;

            $aplicacion = Aplicacion::query()->create([
                'organizacion_id' => $asignacion->organizacion_id,
                'asignacion_destinatario_id' => $destinatario->id,
                'version_instrumento_id' => $version->id,

                /*
                 * NULL si la asignación es anónima. No es que se esconda el
                 * vínculo: NO SE GUARDA. Irreversible por diseño, y es lo que
                 * hace que la gente conteste con la verdad en una NOM-035.
                 */
                'persona_id' => $anonima ? null : $destinatario->persona_id,

                'quien_respondio_persona_id' => $anonima
                    ? null
                    : $destinatario->quien_responde_persona_id,

                'modalidad' => $modalidad,
                'modo_presentacion' => $asignacion->modo_presentacion,
                'estado' => 'iniciada',
                'iniciada_en' => Carbon::now(),

                /*
                 * Edad CONGELADA. Calcularla al calificar normalizaría con la
                 * tabla equivocada a quien cumpla años entre una cosa y otra, y
                 * en preescolar seis meses cambian la norma.
                 *
                 * En anónimas no se guarda: sería un dato que permite reducir
                 * el universo de quién contestó.
                 */
                'edad_meses_al_aplicar' => $anonima
                    ? null
                    : $destinatario->persona->edadEnMeses(),

                'dispositivo' => $dispositivo,
                'ip' => $anonima ? null : $ip,
                'numero_intento' => $this->siguienteIntento($destinatario, $version),
            ]);

            $this->prepararBloques($aplicacion, $version);

            return $aplicacion;
        });
    }

    /**
     * La estructura: qué hay que contestar, SIN los enunciados.
     *
     * @return array<string, mixed>
     */
    public function estructura(Aplicacion $aplicacion): array
    {
        $aplicacion->loadMissing('version.instrumento');

        $bloques = AplicacionBloque::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->with('bloque')
            ->get()
            ->sortBy(fn (AplicacionBloque $estado): int => $estado->bloque->orden);

        $organizacionId = $this->contexto->id();

        return [
            'aplicacion_uuid' => $aplicacion->uuid,
            'instrumento' => $aplicacion->version->instrumento->nombre,
            'modo_presentacion' => $aplicacion->modo_presentacion,
            'estado' => $aplicacion->estado,
            'permite_pausas' => true,

            'bloques' => $bloques->map(fn (AplicacionBloque $estado): array => [
                'clave' => $estado->bloque->clave,
                'titulo' => $estado->bloque->titulo,
                'instrucciones' => $estado->bloque->instrucciones,
                'es_practica' => $estado->bloque->es_practica,
                'tiempo_limite_seg' => $estado->bloque->tiempo_limite_seg,
                'estado' => $estado->estado,

                // Cuántos, no cuáles.
                'total_reactivos' => Reactivo::query()
                    ->where('bloque_id', $estado->bloque_id)
                    ->deContenidoVisiblePara($organizacionId)
                    ->count(),
            ])->values()->all(),

            'bloque_actual' => $this->bloqueActual($aplicacion)?->bloque->clave,
        ];
    }

    /**
     * Entrega un tramo de reactivos de un bloque.
     *
     * Parcelada de verdad: `desde` y `cantidad` con tope. Pedir mil reactivos
     * de una vez no es un caso de uso, es una descarga.
     *
     * @return array<string, mixed>
     *
     * @throws AplicacionInvalida
     */
    public function reactivosDe(
        Aplicacion $aplicacion,
        string $claveBloque,
        int $desde = 0,
        int $cantidad = 10,
    ): array {
        $estado = $this->bloqueDe($aplicacion, $claveBloque);

        if (! $aplicacion->admiteRespuestas()) {
            throw AplicacionInvalida::porNoAdmitirRespuestas($aplicacion->estado);
        }

        // Arranca el cronómetro la primera vez que se piden sus reactivos, no
        // al iniciar la aplicación: si no, una batería de cuatro instrumentos
        // consumiría el tiempo de los cuatro a la vez.
        $this->arrancarBloqueSiHaceFalta($estado);

        $cantidad = max(1, min($cantidad, 50));

        $reactivos = Reactivo::query()
            ->where('bloque_id', $estado->bloque_id)
            ->deContenidoVisiblePara($this->contexto->id())
            ->with(['tipo', 'opciones'])
            ->orderBy('orden')
            ->get();

        // Los saltos se resuelven EN EL SERVIDOR: mandarle el árbol al cliente
        // le entregaría el mapa del instrumento (Doc 02 §2).
        $visibles = $this->saltos->filtrarVisibles($aplicacion, $reactivos);

        $tramo = $visibles->slice($desde, $cantidad);

        $salida = [];

        foreach ($tramo as $reactivo) {
            $opciones = [];

            foreach ($reactivo->opciones as $opcion) {
                $opciones[] = [
                    'codigo' => $opcion->codigo,
                    'texto' => $opcion->texto,
                    'media_id' => $opcion->media_id,
                ];
            }

            $salida[] = [
                'codigo' => $reactivo->codigo,
                'tipo' => $reactivo->tipo->clave,
                'enunciado' => $reactivo->enunciado,
                'obligatorio' => $reactivo->obligatorio,
                'media_id' => $reactivo->media_id,
                'opciones' => $opciones,
            ];
        }

        return [
            'bloque' => $claveBloque,
            'desde' => $desde,
            'total_visibles' => $visibles->count(),
            'cronometro' => $this->cronometroDe($estado),
            'reactivos' => $salida,
        ];
    }

    /**
     * El estado exacto para reanudar (Doc 07 §5).
     *
     * @return array<string, mixed>
     */
    public function estado(Aplicacion $aplicacion): array
    {
        $actual = $this->bloqueActual($aplicacion);

        return [
            'aplicacion_uuid' => $aplicacion->uuid,
            'estado' => $aplicacion->estado,
            'tiempo_efectivo_seg' => $aplicacion->tiempo_efectivo_seg,
            'respondidos' => $aplicacion->respuestas()->count(),
            'bloque_actual' => $actual?->bloque->clave,
            'cronometro' => $actual !== null ? $this->cronometroDe($actual) : null,
            'bloques' => AplicacionBloque::query()
                ->where('aplicacion_id', $aplicacion->id)
                ->with('bloque')
                ->get()
                ->mapWithKeys(fn (AplicacionBloque $estado): array => [
                    $estado->bloque->clave => $estado->estado,
                ])->all(),
        ];
    }

    /**
     * Pausa. El tiempo consumido se congela en el bloque en curso.
     *
     * @throws AplicacionInvalida
     */
    public function pausar(Aplicacion $aplicacion): Aplicacion
    {
        if ($aplicacion->estado !== 'iniciada') {
            throw AplicacionInvalida::porNoEstarEnCurso($aplicacion->estado);
        }

        return DB::transaction(function () use ($aplicacion): Aplicacion {
            $actual = $this->bloqueActual($aplicacion);

            if ($actual !== null && $actual->iniciado_en !== null) {
                /*
                 * Se acumula lo consumido y se borra `iniciado_en`. Es lo que
                 * hace que una pausa de dos horas no cuente como tiempo de
                 * respuesta: al reanudar, el cronómetro sigue donde se quedó.
                 */
                $actual->update([
                    'tiempo_consumido_seg' => $actual->tiempo_consumido_seg
                        + (int) $actual->iniciado_en->diffInSeconds(Carbon::now()),
                    'iniciado_en' => null,
                ]);
            }

            $aplicacion->update([
                'estado' => 'en_pausa',
                'tiempo_efectivo_seg' => $aplicacion->tiempo_efectivo_seg
                    + (int) $aplicacion->iniciada_en->diffInSeconds(Carbon::now()),
            ]);

            return $aplicacion;
        });
    }

    public function reanudar(Aplicacion $aplicacion): Aplicacion
    {
        if ($aplicacion->estado !== 'en_pausa') {
            return $aplicacion;
        }

        $aplicacion->update(['estado' => 'iniciada', 'iniciada_en' => Carbon::now()]);

        $actual = $this->bloqueActual($aplicacion);

        if ($actual !== null && $actual->estaEnCurso()) {
            $actual->update(['iniciado_en' => Carbon::now()]);
        }

        return $aplicacion;
    }

    /**
     * Cierra la aplicación y deja el pipeline listo para arrancar.
     *
     * @throws AplicacionInvalida
     */
    public function finalizar(Aplicacion $aplicacion): Aplicacion
    {
        if (! $aplicacion->admiteRespuestas()) {
            throw AplicacionInvalida::porNoAdmitirRespuestas($aplicacion->estado);
        }

        return DB::transaction(function () use ($aplicacion): Aplicacion {
            AplicacionBloque::query()
                ->where('aplicacion_id', $aplicacion->id)
                ->whereIn('estado', ['pendiente', 'en_curso'])
                ->update(['estado' => 'completado', 'finalizado_en' => Carbon::now()]);

            $aplicacion->update([
                'estado' => 'completada',
                'finalizada_en' => Carbon::now(),
                'tiempo_efectivo_seg' => $aplicacion->tiempo_efectivo_seg
                    + (int) $aplicacion->iniciada_en->diffInSeconds(Carbon::now()),
            ]);

            $aplicacion->destinatario->update(['estado' => 'completada']);

            /*
             * El pipeline arranca DESPUÉS de que la transacción confirme
             * (`afterCommit`): encolarlo dentro haría que el worker pudiera
             * recoger el job antes de que la aplicación esté marcada como
             * completada, y calificaría una que sigue abierta.
             */
            CalificarAplicacion::dispatch($aplicacion->id)->afterCommit();

            return $aplicacion;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cronometroDe(AplicacionBloque $estado): ?array
    {
        $restante = $estado->restanteSeg();

        if ($restante === null) {
            return null;
        }

        return [
            'bloque' => $estado->bloque->clave,
            'restante_seg' => $restante,
            'expirado' => $restante === 0,
        ];
    }

    /**
     * @throws AplicacionInvalida
     */
    public function bloqueDe(Aplicacion $aplicacion, string $clave): AplicacionBloque
    {
        $estado = AplicacionBloque::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->whereHas('bloque', fn ($consulta) => $consulta->where('clave', $clave))
            ->with('bloque')
            ->first();

        if ($estado === null) {
            throw AplicacionInvalida::porBloqueDesconocido($clave);
        }

        return $estado;
    }

    public function bloqueActual(Aplicacion $aplicacion): ?AplicacionBloque
    {
        return AplicacionBloque::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->whereIn('estado', ['en_curso', 'pendiente'])
            ->with('bloque')
            ->get()
            ->sortBy(fn (AplicacionBloque $estado): int => $estado->bloque->orden)
            ->first();
    }

    private function arrancarBloqueSiHaceFalta(AplicacionBloque $estado): void
    {
        if ($estado->estado === 'pendiente') {
            $estado->update(['estado' => 'en_curso', 'iniciado_en' => Carbon::now()]);

            return;
        }

        // Reanudación: el bloque estaba en curso pero sin reloj corriendo.
        if ($estado->estaEnCurso() && $estado->iniciado_en === null) {
            $estado->update(['iniciado_en' => Carbon::now()]);
        }
    }

    private function prepararBloques(Aplicacion $aplicacion, VersionInstrumento $version): void
    {
        $bloques = Bloque::query()
            ->where('version_instrumento_id', $version->id)
            ->orderBy('orden')
            ->get();

        foreach ($bloques as $bloque) {
            AplicacionBloque::query()->create([
                'aplicacion_id' => $aplicacion->id,
                'bloque_id' => $bloque->id,
                'estado' => 'pendiente',
            ]);
        }
    }

    /**
     * @throws AplicacionInvalida
     */
    private function versionDe(AsignacionDestinatario $destinatario): VersionInstrumento
    {
        $version = $destinatario->asignacion->version;

        if ($version === null) {
            /*
             * Es una batería: el llamador tiene que decir cuál de sus
             * instrumentos se va a contestar. Adivinar "el primero" haría que
             * recargar la pantalla arrancara siempre el mismo.
             */
            throw AplicacionInvalida::porFaltarInstrumentoDeBateria();
        }

        return $version;
    }

    /**
     * @throws AplicacionInvalida
     */
    private function exigirQueQuedenIntentos(
        AsignacionDestinatario $destinatario,
        VersionInstrumento $version,
    ): void {
        $usados = Aplicacion::query()
            ->withoutGlobalScopes()
            ->where('asignacion_destinatario_id', $destinatario->id)
            ->where('version_instrumento_id', $version->id)
            ->whereIn('estado', ['completada', 'expirada'])
            ->count();

        if ($usados >= $destinatario->asignacion->intentos_permitidos) {
            throw AplicacionInvalida::porNoQuedarIntentos();
        }
    }

    private function siguienteIntento(
        AsignacionDestinatario $destinatario,
        VersionInstrumento $version,
    ): int {
        return 1 + (int) Aplicacion::query()
            ->withoutGlobalScopes()
            ->where('asignacion_destinatario_id', $destinatario->id)
            ->where('version_instrumento_id', $version->id)
            ->max('numero_intento');
    }
}
