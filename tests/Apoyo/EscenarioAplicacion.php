<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Catalogo\Modelos\Bloque;
use App\Domain\Catalogo\Modelos\CentinelaCondicion;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use App\Domain\Evaluaciones\Servicios\MotorAplicacion;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Support\Str;

/**
 * Monta una aplicación lista para contestar.
 */
class EscenarioAplicacion
{
    public EscenarioAsignacion $asignacion;

    public Persona $persona;

    public AsignacionDestinatario $destinatario;

    public function __construct(public EscenarioTenant $tenant, ?int $tiempoLimiteSeg = null)
    {
        $this->asignacion = new EscenarioAsignacion($tenant);

        if ($tiempoLimiteSeg !== null) {
            $this->asignacion->instrumento->bloque->update([
                'tiempo_limite_seg' => $tiempoLimiteSeg,
            ]);
        }

        $this->persona = $tenant->persona();

        $orden = $this->asignacion->individual($tenant->persona(), [$this->persona]);
        $this->destinatario = $orden->destinatarios()->first();
        $this->destinatario->setRelation('asignacion', $orden);
    }

    /**
     * Agrega reactivos al bloque único del instrumento.
     *
     * @return list<Reactivo>
     */
    public function reactivos(int $cuantos, string $tipo = 'likert_5'): array
    {
        $creados = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $creados[] = $this->asignacion->instrumento->reactivo($tipo, 'E1');
        }

        return $creados;
    }

    public function centinela(string $mensaje = 'Riesgo detectado: atender de inmediato.'): Reactivo
    {
        $reactivo = $this->asignacion->instrumento->reactivo(
            'likert_5',
            'E1',
            esCentinela: true
        );

        CentinelaCondicion::query()->create([
            'reactivo_id' => $reactivo->id,
            'operador' => '>',
            'valor' => 0,
            'severidad' => 'critica',
            'mensaje' => $mensaje,
        ]);

        return $reactivo;
    }

    public function bloqueExtra(string $clave, ?int $tiempoLimiteSeg = null): Bloque
    {
        return Bloque::query()->create([
            'version_instrumento_id' => $this->asignacion->instrumento->version->id,
            'clave' => $clave,
            'titulo' => 'Bloque '.$clave,
            'orden' => 10,
            'tiempo_limite_seg' => $tiempoLimiteSeg,
        ]);
    }

    public function iniciar(): Aplicacion
    {
        return app(MotorAplicacion::class)->iniciar($this->destinatario);
    }

    /**
     * Un renglón de lote listo para mandar.
     *
     * @return array<string, mixed>
     */
    public static function respuesta(
        string $codigoReactivo,
        float $valor = 1,
        ?string $uuidCliente = null,
        ?string $respondidaEn = null,
    ): array {
        return [
            'uuid_cliente' => $uuidCliente ?? (string) Str::uuid(),
            'reactivo_codigo' => $codigoReactivo,
            'valor_numerico' => $valor,
            'tiempo_respuesta_ms' => 1500,
            'respondida_en' => $respondidaEn,
        ];
    }
}
