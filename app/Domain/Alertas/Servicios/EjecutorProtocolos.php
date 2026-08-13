<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Accesos\Servicios\RegistroBitacora;
use App\Domain\Alertas\Modelos\ProtocoloEjecucion;
use App\Domain\Alertas\Modelos\ProtocoloRegla;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\Proposito;
use App\Domain\Evaluaciones\Servicios\CreadorAsignaciones;
use App\Domain\Interpretacion\Modelos\ResultadoEscala;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Etapa 6 — escalonamiento automático (Doc 05 §2).
 *
 * Un M-CHAT de riesgo medio dispara la entrevista de seguimiento; un PHQ-9 alto
 * notifica al psicólogo. Las reglas son datos, no código: cada tenant tiene su
 * protocolo y programarlos sería un `if` por organización.
 *
 * DOS REGLAS QUE NO SE NEGOCIAN:
 *
 * 1. **Nada pasa en silencio.** Toda acción automática genera alerta y deja
 *    bitácora. Un sistema que asigna evaluaciones al expediente de alguien sin
 *    que nadie se entere es un sistema que nadie puede auditar, y lo que está
 *    haciendo es una decisión clínica.
 * 2. **Una regla corre UNA VEZ por aplicación.** Recalificar no vuelve a
 *    mandarle a la familia la liga de la entrevista ni al psicólogo la misma
 *    alarma; a la tercera deja de mirarlas.
 */
class EjecutorProtocolos
{
    public function __construct(
        private readonly AlertaService $alertas,
        private readonly CreadorAsignaciones $asignaciones,
        private readonly RegistroBitacora $bitacora,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    public function ejecutar(Aplicacion $aplicacion): int
    {
        $reglas = ProtocoloRegla::query()
            ->aplicablesA($aplicacion->version_instrumento_id, $aplicacion->organizacion_id)
            ->get();

        if ($reglas->isEmpty()) {
            return 0;
        }

        $resultados = ResultadoEscala::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->get();

        $ejecutadas = 0;

        foreach ($reglas as $regla) {
            if (! $this->cumple($regla, $resultados)) {
                continue;
            }

            if ($this->yaCorrio($regla, $aplicacion)) {
                continue;
            }

            $ejecutadas += $this->aplicar($regla, $aplicacion) ? 1 : 0;
        }

        return $ejecutadas;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ResultadoEscala>  $resultados
     */
    private function cumple(ProtocoloRegla $regla, $resultados): bool
    {
        $resultado = $resultados->firstWhere('escala_id', $regla->condicion_escala_id);

        if (! $resultado instanceof ResultadoEscala) {
            return false;
        }

        /*
         * El semáforo se compara como TEXTO contra la etiqueta. Un M-CHAT de
         * riesgo medio no es «mayor que 3»: es la categoría que el algoritmo de
         * dos etapas decidió, y compararla como número perdería justamente el
         * matiz de «pendiente de entrevista» frente a «medio ya resuelto».
         */
        if ($regla->tipo_puntaje === 'semaforo') {
            return match ($regla->operador) {
                '=', '==' => $resultado->etiqueta_norma === $regla->valor,
                '!=' => $resultado->etiqueta_norma !== $regla->valor,
                'contiene' => $resultado->etiqueta_norma !== null
                    && str_contains($resultado->etiqueta_norma, $regla->valor),
                default => false,
            };
        }

        $valor = $resultado->valorEnTipo($regla->tipo_puntaje);

        if ($valor === null || ! is_numeric($regla->valor)) {
            return false;
        }

        $umbral = (float) $regla->valor;

        return match ($regla->operador) {
            '>' => $valor > $umbral,
            '>=' => $valor >= $umbral,
            '<' => $valor < $umbral,
            '<=' => $valor <= $umbral,
            '=', '==' => $valor === $umbral,
            default => false,
        };
    }

    private function yaCorrio(ProtocoloRegla $regla, Aplicacion $aplicacion): bool
    {
        return ProtocoloEjecucion::query()
            ->where('protocolo_regla_id', $regla->id)
            ->where('aplicacion_id', $aplicacion->id)
            ->exists();
    }

    private function aplicar(ProtocoloRegla $regla, Aplicacion $aplicacion): bool
    {
        $resultado = match ($regla->entonces_accion) {
            'asignar_instrumento', 'asignar_bateria' => $this->asignarSegundaEtapa($regla, $aplicacion),
            'notificar_rol' => 'Se notificó al rol responsable.',
            'marcar_seguimiento' => 'Se marcó para seguimiento.',
            default => null,
        };

        if ($resultado === null) {
            return false;
        }

        DB::transaction(function () use ($regla, $aplicacion, $resultado): void {
            ProtocoloEjecucion::query()->create([
                'protocolo_regla_id' => $regla->id,
                'aplicacion_id' => $aplicacion->id,
                'resultado' => mb_substr($resultado, 0, 160),
                'ejecutada_en' => Carbon::now(),
            ]);
        });

        /*
         * La alerta va SIEMPRE, sea cual sea la acción. Es lo que convierte
         * «el sistema hizo algo» en «alguien se enteró de que el sistema hizo
         * algo», y sin ella un protocolo automático sería una caja negra
         * operando sobre expedientes.
         */
        $this->alertas->porResultado(
            $aplicacion,
            tipo: 'protocolo',
            severidad: 'alta',
            mensaje: ($regla->nota ?? 'Protocolo automático').': '.$resultado,
        );

        $this->bitacora->registrarAccion(
            organizacionId: $aplicacion->organizacion_id,
            accion: 'protocolo.ejecutado',
            recursoTipo: 'aplicaciones',
            recursoId: $aplicacion->id,
            personaAfectadaId: $aplicacion->persona_id,
            motivo: mb_substr($resultado, 0, 160),
        );

        return true;
    }

    /**
     * Asigna la evaluación de segunda etapa a la misma persona.
     *
     * DOS COSAS SE HEREDAN DE LA ASIGNACIÓN ORIGINAL, y las dos a propósito:
     *
     * - **El propósito.** No se busca uno llamado "seguimiento": se reusa el
     *   que trajo a la persona hasta aquí. Depender de un propósito con clave
     *   mágica haría que el escalonamiento fallara en silencio en cualquier
     *   tenant que no lo hubiera creado, que son todos hasta que alguien se
     *   acuerde. Además el propósito lleva el tipo de consentimiento y la
     *   vigencia, y los de la segunda etapa son los mismos.
     * - **Quien la ordena.** El autor es quien asignó la primera evaluación, no
     *   la persona evaluada. Un M-CHAT de seguimiento lo pide quien pidió el
     *   tamizaje; poner de autora a la madre que contestó diría que ella se lo
     *   asignó a su hijo, y eso es falso en el expediente.
     */
    private function asignarSegundaEtapa(ProtocoloRegla $regla, Aplicacion $aplicacion): ?string
    {
        if ($regla->entonces_ref_id === null || $aplicacion->persona_id === null) {
            /*
             * Sin persona no hay a quién asignarle nada: la aplicación es
             * anónima. El riesgo queda en la alerta y el protocolo del tenant
             * dice qué hacer con un agregado, que es dirigirse al centro de
             * trabajo y no a alguien.
             */
            return null;
        }

        $original = $aplicacion->destinatario->asignacion;

        $proposito = Proposito::query()->find($original->proposito_id);
        $autor = Persona::query()->find($original->asignado_por);

        if ($proposito === null || $autor === null) {
            Log::warning('No se pudo reconstruir el origen para el escalonamiento', [
                'regla_id' => $regla->id,
                'aplicacion_id' => $aplicacion->id,
            ]);

            return null;
        }

        /*
         * La cola no tiene organización activa y el global scope falla cerrado:
         * sin fijar el contexto, `crear()` no encontraría ni a la persona
         * destinataria. Se fija el de la aplicación —no se quita la
         * restricción— porque lo que se va a ESCRIBIR tiene que caer en el
         * tenant correcto, y `sinRestriccion` lo dejaría sin dueño.
         */
        $anterior = $this->contexto->organizacion();

        try {
            $this->contexto->establecer($aplicacion->organizacion);

            $asignacion = $this->asignaciones->crear(
                proposito: $proposito,
                autor: $autor,
                origenTipo: 'individual',
                destinatariosUuid: [$aplicacion->persona->uuid],
                versionInstrumentoId: $regla->entonces_accion === 'asignar_instrumento'
                    ? $regla->entonces_ref_id
                    : null,
                bateriaId: $regla->entonces_accion === 'asignar_bateria'
                    ? $regla->entonces_ref_id
                    : null,
            );
        } catch (Throwable $error) {
            /*
             * Si la asignación automática falla —por ejemplo porque el
             * instrumento de segunda etapa tiene centinelas y el tenant no
             * registró protocolo— NO se traga el error en silencio: se deja
             * dicho, porque alguien tiene que ir a arreglarlo.
             */
            Log::error('No se pudo asignar la segunda etapa del protocolo', [
                'regla_id' => $regla->id,
                'aplicacion_id' => $aplicacion->id,
                'error' => $error->getMessage(),
            ]);

            return null;
        } finally {
            // El contexto vuelve como estaba: este servicio corre dentro de un
            // job encadenado, y dejarlo movido afectaría a la etapa siguiente.
            if ($anterior === null) {
                $this->contexto->limpiar();
            } else {
                $this->contexto->establecer($anterior);
            }
        }

        return 'Se asignó la evaluación de seguimiento con folio '.$asignacion->folio.'.';
    }
}
