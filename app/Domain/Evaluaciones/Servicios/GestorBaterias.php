<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Catalogo\Modelos\TenantInstrumento;
use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\BateriaInvalida;
use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\Bateria;
use App\Domain\Evaluaciones\Modelos\BateriaInstrumento;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Composición de baterías.
 *
 * La regla que gobierna: SÓLO se puede meter en una batería lo que esta
 * organización PUEDE APLICAR. Una batería que incluya un instrumento no
 * habilitado se arma sin protestar y revienta al asignarla, delante de la
 * persona que iba a contestarla.
 */
class GestorBaterias
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    public function crear(string $clave, string $nombre, ?string $descripcion = null): Bateria
    {
        return Bateria::query()->create([
            'organizacion_id' => $this->organizacionActiva(),
            'clave' => $clave,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'estado' => 'borrador',
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Bateria $bateria, array $datos): Bateria
    {
        $this->exigirPropia($bateria);

        $bateria->update([
            'nombre' => $datos['nombre'] ?? $bateria->nombre,
            'descripcion' => $datos['descripcion'] ?? $bateria->descripcion,
            'orden_instrumentos' => $datos['orden_instrumentos'] ?? $bateria->orden_instrumentos,
            'permite_pausas' => $datos['permite_pausas'] ?? $bateria->permite_pausas,
            'tiempo_total_min' => $datos['tiempo_total_min'] ?? $bateria->tiempo_total_min,
        ]);

        return $bateria;
    }

    /**
     * Agrega un instrumento al final.
     *
     * @throws BateriaInvalida
     */
    public function agregar(
        Bateria $bateria,
        VersionInstrumento $version,
        bool $obligatorio = true,
    ): BateriaInstrumento {
        $this->exigirPropia($bateria);
        $this->exigirQueSePuedaAplicar($version);

        $siguiente = 1 + (int) BateriaInstrumento::query()
            ->where('bateria_id', $bateria->id)
            ->max('orden');

        return BateriaInstrumento::query()->updateOrCreate(
            ['bateria_id' => $bateria->id, 'version_instrumento_id' => $version->id],
            ['orden' => $siguiente, 'obligatorio' => $obligatorio]
        );
    }

    public function quitar(Bateria $bateria, BateriaInstrumento $renglon): void
    {
        $this->exigirPropia($bateria);
        $this->exigirEditable($bateria);

        if ($renglon->bateria_id !== $bateria->id) {
            // Un id de otro renglón no puede sacar un instrumento de esta
            // batería.
            abort(404);
        }

        $renglon->delete();
    }

    /**
     * Reordena la batería completa. Recibe los ids en el orden nuevo.
     *
     * Se guarda TODO el orden de una vez y no una posición suelta: al
     * arrastrar un renglón cambian las posiciones de varios, y mandar sólo el
     * movido dejaría al servidor recalculando lo que el cliente ya sabe.
     *
     * @param  list<int>  $idsEnOrden
     *
     * @throws BateriaInvalida
     */
    public function reordenar(Bateria $bateria, array $idsEnOrden): void
    {
        $this->exigirPropia($bateria);
        $this->exigirEditable($bateria);

        $actuales = BateriaInstrumento::query()
            ->where('bateria_id', $bateria->id)
            ->pluck('id')
            ->all();

        /*
         * El orden nuevo tiene que ser una PERMUTACIÓN del actual. Aceptar una
         * lista parcial dejaría renglones con orden viejo mezclados con los
         * nuevos, y aceptar ids ajenos permitiría reordenar otra batería.
         */
        if (count($idsEnOrden) !== count($actuales)
            || array_diff($idsEnOrden, $actuales) !== []) {
            throw BateriaInvalida::porOrdenIncompleto();
        }

        DB::transaction(function () use ($idsEnOrden): void {
            foreach ($idsEnOrden as $posicion => $id) {
                BateriaInstrumento::query()
                    ->where('id', $id)
                    ->update(['orden' => $posicion + 1]);
            }
        });
    }

    /**
     * Activa la batería: a partir de aquí se puede asignar.
     *
     * @throws BateriaInvalida
     */
    public function activar(Bateria $bateria): Bateria
    {
        $this->exigirPropia($bateria);

        if ($bateria->instrumentos()->count() === 0) {
            throw BateriaInvalida::porEstarVacia();
        }

        $bateria->update(['estado' => 'activa']);

        return $bateria;
    }

    /**
     * Archiva. No se borra: las asignaciones históricas apuntan a ella.
     */
    public function archivar(Bateria $bateria): Bateria
    {
        $this->exigirPropia($bateria);
        $bateria->update(['estado' => 'archivada']);

        return $bateria;
    }

    /**
     * Las versiones que ESTA organización puede meter en una batería.
     *
     * @return \Illuminate\Support\Collection<int, VersionInstrumento>
     */
    public function versionesDisponibles(): \Illuminate\Support\Collection
    {
        $habilitadas = TenantInstrumento::query()
            ->where('estado', TenantInstrumento::HABILITADO)
            ->pluck('version_instrumento_id');

        /** @var \Illuminate\Support\Collection<int, VersionInstrumento> */
        return VersionInstrumento::query()
            ->whereIn('id', $habilitadas)
            ->with('instrumento')
            ->get();
    }

    /**
     * @throws BateriaInvalida
     */
    private function exigirQueSePuedaAplicar(VersionInstrumento $version): void
    {
        $habilitado = TenantInstrumento::query()
            ->where('version_instrumento_id', $version->id)
            ->where('estado', TenantInstrumento::HABILITADO)
            ->exists();

        if (! $habilitado) {
            throw BateriaInvalida::porInstrumentoNoHabilitado($version->instrumento->nombre);
        }

        if (! $version->instrumento->seAplicaEnLinea()) {
            /*
             * Un instrumento de sólo captura no se puede poner en una batería:
             * la batería se contesta en línea, y ese no se aplica en línea por
             * prohibición de la editorial (Doc 01 §6).
             */
            throw BateriaInvalida::porNoAplicarseEnLinea($version->instrumento->nombre);
        }
    }

    /**
     * Una batería con asignaciones vivas no se reordena.
     *
     * Cambiar el orden a media campaña haría que dos personas contestaran la
     * misma batería en secuencias distintas, y el orden afecta al resultado
     * —fatiga, aprendizaje entre instrumentos—. Para cambiarla se archiva y se
     * crea otra.
     *
     * @throws BateriaInvalida
     */
    private function exigirEditable(Bateria $bateria): void
    {
        $enUso = Asignacion::query()
            ->where('bateria_id', $bateria->id)
            ->whereIn('estado', ['activa'])
            ->exists();

        if ($enUso) {
            throw BateriaInvalida::porEstarEnUso();
        }
    }

    private function exigirPropia(Bateria $bateria): void
    {
        if ($bateria->organizacion_id !== $this->organizacionActiva()) {
            // 404 y no 403: confirmar que existe diría cómo tiene armadas sus
            // baterías otra organización.
            abort(404);
        }
    }

    private function organizacionActiva(): int
    {
        $id = $this->contexto->id();

        if ($id === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        return $id;
    }
}
