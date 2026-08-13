<?php

declare(strict_types=1);

namespace App\Domain\Expedientes\Servicios;

use App\Domain\Accesos\Servicios\AccesoService;
use App\Domain\Expedientes\Modelos\Expediente;
use App\Domain\Expedientes\Modelos\SeccionExpediente;
use App\Domain\Personas\Modelos\Persona;

/**
 * Arma el expediente que un actor concreto PUEDE ver.
 *
 * El filtrado ocurre en el servidor, sección por sección, pasando cada una por
 * AccesoService. No se manda el expediente completo para que el frontend
 * esconda lo que no toca: una sección clínica que viaja al navegador ya se
 * fugó, aunque no se dibuje.
 */
class VistaExpediente
{
    public function __construct(
        private readonly CapturaExpediente $captura,
        private readonly AccesoService $accesos,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paraActor(Persona $sujeto, Persona $actor): array
    {
        $expediente = $this->captura->expedienteDe($sujeto);
        $vigentes = $this->captura->valoresVigentes($expediente);

        $secciones = SeccionExpediente::query()
            ->with(['nivel', 'campos' => fn ($consulta) => $consulta->where('activo', true)
                ->with(['nivel', 'catalogo.opciones'])
                ->orderBy('orden')])
            ->orderBy('orden')
            ->get();

        $salida = [];

        foreach ($secciones as $seccion) {
            /*
             * Una decisión por SECCIÓN, no una por el expediente entero. Es lo
             * que hace que un reclutador vea datos generales y no vea
             * antecedentes médicos con el mismo permiso: cada sección declara
             * su sensibilidad y AccesoService la compara con el tope del rol.
             */
            $decision = $this->accesos->autorizar($actor, 'expediente.ver', $sujeto, $seccion);

            if ($decision->denegado()) {
                continue;
            }

            $campos = [];

            foreach ($seccion->campos as $campo) {
                $valor = $vigentes->get($campo->id);

                $campos[] = [
                    'id' => $campo->id,
                    'clave' => $campo->clave,
                    'etiqueta' => $campo->etiqueta,
                    'tipo_dato' => $campo->tipo_dato,
                    'quien_puede_llenar' => $campo->quien_puede_llenar,
                    'obligatorio' => $campo->obligatorio,
                    'valor' => $valor?->contenido(),
                    'version' => $valor?->version,
                    'opciones' => $campo->tipo_dato === 'catalogo'
                        ? $campo->catalogo?->opciones->map(fn ($opcion): array => [
                            'id' => $opcion->id,
                            'etiqueta' => $opcion->etiqueta,
                        ])->all() ?? []
                        : [],
                ];
            }

            $salida[] = [
                'clave' => $seccion->clave,
                'nombre' => $seccion->nombre,
                'nivel_sensibilidad' => $seccion->nivelSensibilidad(),
                'campos' => $campos,
            ];
        }

        return $salida;
    }

    /**
     * Lo que el titular o el tutor pueden capturar desde el portal.
     *
     * Sólo los campos marcados `titular` o `tutor`: el portal de autollenado
     * no ofrece lo que responde un profesional, ni siquiera en gris.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paraAutollenado(Persona $sujeto, string $rol = 'titular'): array
    {
        $expediente = $this->captura->expedienteDe($sujeto);
        $vigentes = $this->captura->valoresVigentes($expediente);

        $permitidos = $rol === 'titular' ? ['titular'] : ['titular', 'tutor'];

        $secciones = SeccionExpediente::query()
            ->with(['campos' => fn ($consulta) => $consulta
                ->where('activo', true)
                ->whereIn('quien_puede_llenar', $permitidos)
                ->with('catalogo.opciones')
                ->orderBy('orden')])
            ->orderBy('orden')
            ->get();

        $pendientes = $this->captura->pendientesDeValidar($expediente)->keyBy('campo_id');

        $salida = [];

        foreach ($secciones as $seccion) {
            if ($seccion->campos->isEmpty()) {
                continue;
            }

            $campos = [];

            foreach ($seccion->campos as $campo) {
                $pendiente = $pendientes->get($campo->id);

                $campos[] = [
                    'id' => $campo->id,
                    'etiqueta' => $campo->etiqueta,
                    'tipo_dato' => $campo->tipo_dato,
                    'obligatorio' => $campo->obligatorio,
                    'valor' => $vigentes->get($campo->id)?->contenido(),

                    // Lo que la persona capturó y todavía nadie validó. Se le
                    // muestra para que no lo capture dos veces creyendo que se
                    // perdió.
                    'pendiente' => $pendiente?->contenido(),

                    'opciones' => $campo->tipo_dato === 'catalogo'
                        ? $campo->catalogo?->opciones->map(fn ($opcion): array => [
                            'id' => $opcion->id,
                            'etiqueta' => $opcion->etiqueta,
                        ])->all() ?? []
                        : [],
                ];
            }

            $salida[] = [
                'clave' => $seccion->clave,
                'nombre' => $seccion->nombre,
                'campos' => $campos,
            ];
        }

        return $salida;
    }
}
