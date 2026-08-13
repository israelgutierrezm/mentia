<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Catalogo\Modelos\Escala;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Modelos\CapturaProtocoloEscala;
use App\Domain\Personas\Modelos\Persona;
use App\Jobs\CalificarAplicacion;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Captura de resultados de instrumentos que NO se aplican en línea.
 *
 * El examinador aplica el WISC en papel y aquí registra los puntajes. La
 * aplicación resultante entra al pipeline desde la etapa de NORMALIZACIÓN
 * (Doc 03 §M7): no hay respuestas que sumar, el bruto ya viene dado.
 */
class CapturaProtocolo
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * @param  list<array<string, mixed>>  $escalas
     */
    public function registrar(
        string $personaUuid,
        int $versionInstrumentoId,
        string $fechaAplicacion,
        array $escalas,
        Persona $capturadoPor,
        ?string $observaciones = null,
    ): Aplicacion {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        $persona = Persona::query()
            ->where('uuid', $personaUuid)
            ->whereHas('vinculaciones', fn ($consulta) => $consulta->where('estado', 'activa'))
            ->firstOrFail();

        $version = VersionInstrumento::query()->findOrFail($versionInstrumentoId);

        if ($version->instrumento->modo_calificacion !== 'captura_protocolo'
            && $version->instrumento->seAplicaEnLinea()) {
            /*
             * Un instrumento que SÍ se aplica en línea no se captura a mano:
             * hacerlo saltaría los cronómetros, la validez y el registro de
             * tiempos, y produciría un resultado que parece igual y no lo es.
             */
            throw new RuntimeException(
                'Ese instrumento se aplica en línea. La captura de protocolo es sólo para '
                .'los que la editorial no permite administrar desde el sistema.'
            );
        }

        $fecha = Carbon::parse($fechaAplicacion);

        return DB::transaction(function () use (
            $persona, $version, $fecha, $escalas, $capturadoPor, $observaciones, $organizacionId
        ): Aplicacion {
            $destinatario = $this->destinatarioDe($persona, $version, $organizacionId);

            $aplicacion = Aplicacion::query()->create([
                'organizacion_id' => $organizacionId,
                'asignacion_destinatario_id' => $destinatario->id,
                'version_instrumento_id' => $version->id,
                'persona_id' => $persona->id,
                'quien_respondio_persona_id' => $capturadoPor->id,
                'modalidad' => 'captura_protocolo',
                'modo_presentacion' => 'examinador',
                'estado' => 'completada',
                'iniciada_en' => $fecha,
                'finalizada_en' => $fecha,

                // La edad al APLICAR, que es la fecha del protocolo de papel,
                // no la de hoy: capturar un WISC de hace tres meses tiene que
                // normalizar con la edad que la persona tenía ese día.
                'edad_meses_al_aplicar' => $persona->edadEnMeses($fecha),
            ]);

            foreach ($escalas as $entrada) {
                $escala = Escala::query()
                    ->where('version_instrumento_id', $version->id)
                    ->where('clave', $entrada['clave'])
                    ->first();

                if ($escala === null) {
                    throw new RuntimeException(
                        sprintf('La escala «%s» no existe en este instrumento.', $entrada['clave'])
                    );
                }

                CapturaProtocoloEscala::query()->create([
                    'aplicacion_id' => $aplicacion->id,
                    'escala_id' => $escala->id,
                    'puntaje_bruto' => $entrada['puntaje_bruto'],
                    'puntaje_escalar' => $entrada['puntaje_escalar'] ?? null,
                    'observaciones' => $observaciones,
                    'capturado_por' => $capturadoPor->id,
                ]);
            }

            CalificarAplicacion::dispatch($aplicacion->id)->afterCommit();

            return $aplicacion;
        });
    }

    /**
     * Un destinatario para colgar la captura.
     *
     * Una captura de protocolo no siempre viene de una asignación —el
     * profesional aplicó el WISC y punto—, pero `aplicaciones` exige uno.
     * Se reutiliza el más reciente de esa persona para ese instrumento, y si no
     * hay ninguno se falla: crear una asignación fantasma escondería que
     * alguien está capturando resultados fuera de todo proceso.
     */
    private function destinatarioDe(
        Persona $persona,
        VersionInstrumento $version,
        int $organizacionId,
    ): AsignacionDestinatario {
        $destinatario = AsignacionDestinatario::query()
            ->where('persona_id', $persona->id)
            ->whereHas('asignacion', function ($consulta) use ($version, $organizacionId): void {
                $consulta->withoutGlobalScopes()
                    ->where('organizacion_id', $organizacionId)
                    ->where('version_instrumento_id', $version->id);
            })
            ->orderByDesc('id')
            ->first();

        if ($destinatario === null) {
            throw new RuntimeException(
                'No hay una asignación de este instrumento para esa persona. Créala antes de '
                .'capturar el protocolo: sin ella no queda registro de por qué se aplicó.'
            );
        }

        return $destinatario;
    }
}
