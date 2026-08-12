<?php

declare(strict_types=1);

namespace App\Domain\Organizaciones\Servicios;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use App\Domain\Organizaciones\Modelos\AgrupacionMiembro;
use App\Domain\Organizaciones\Modelos\TipoAgrupacion;
use App\Domain\Organizaciones\Modelos\Unidad;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Agrupaciones y sus miembros.
 */
class GestorAgrupaciones
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Agrupacion
    {
        return Agrupacion::query()->create([
            'unidad_id' => $this->unidadValidada($datos)?->id,
            'tipo_agrupacion_id' => $this->tipoValidado($datos)->id,
            'nombre' => $datos['nombre'],
            'periodo_inicio' => $datos['periodo_inicio'] ?? null,
            'periodo_fin' => $datos['periodo_fin'] ?? null,
            'estado' => $datos['estado'] ?? 'activa',
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Agrupacion $agrupacion, array $datos): Agrupacion
    {
        $agrupacion->update([
            'unidad_id' => $this->unidadValidada($datos)?->id,
            'tipo_agrupacion_id' => $this->tipoValidado($datos)->id,
            'nombre' => $datos['nombre'],
            'periodo_inicio' => $datos['periodo_inicio'] ?? null,
            'periodo_fin' => $datos['periodo_fin'] ?? null,
            'estado' => $datos['estado'] ?? $agrupacion->estado,
        ]);

        return $agrupacion;
    }

    /**
     * Inscribe a una persona. Si ya estuvo y se dio de baja, la REACTIVA.
     *
     * Reactivar y no crear otra fila: dos membresías vigentes de la misma
     * persona en el mismo grupo harían que una asignación grupal le llegue
     * dos veces, y que el conteo de avance mienta.
     */
    public function inscribir(
        Agrupacion $agrupacion,
        Persona $persona,
        string $rolEnAgrupacion = 'evaluado',
    ): AgrupacionMiembro {
        $existente = AgrupacionMiembro::query()
            ->where('agrupacion_id', $agrupacion->id)
            ->where('persona_id', $persona->id)
            ->first();

        if ($existente !== null) {
            $existente->update([
                'fecha_baja' => null,
                'rol_en_agrupacion' => $rolEnAgrupacion,
            ]);

            return $existente;
        }

        return AgrupacionMiembro::query()->create([
            'agrupacion_id' => $agrupacion->id,
            'persona_id' => $persona->id,
            'rol_en_agrupacion' => $rolEnAgrupacion,
            'fecha_alta' => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * Baja lógica con fecha. Es lo que corta el alcance sin borrar el rastro
     * de que esa persona SÍ estuvo en ese grupo, que es parte de su línea de
     * vida institucional.
     */
    public function darDeBaja(AgrupacionMiembro $miembro): AgrupacionMiembro
    {
        $miembro->update(['fecha_baja' => Carbon::now()->toDateString()]);

        return $miembro;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function unidadValidada(array $datos): ?Unidad
    {
        $unidadId = $datos['unidad_id'] ?? null;

        if ($unidadId === null) {
            return null;
        }

        // Con global scope: una unidad de otro tenant no se encuentra.
        return Unidad::query()->findOrFail($unidadId);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function tipoValidado(array $datos): TipoAgrupacion
    {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        /*
         * TipoAgrupacion no lleva global scope —las plantillas del sistema
         * tienen organizacion_id NULL y el scope las escondería—, así que el
         * acotamiento se hace aquí: las del sistema MÁS las propias de este
         * tenant, nunca las de otro.
         */
        return TipoAgrupacion::query()
            ->disponiblesPara($organizacionId)
            ->findOrFail($datos['tipo_agrupacion_id']);
    }
}
