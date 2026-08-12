<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accesos\Modelos\PlantillaRol;
use App\Domain\Organizaciones\Modelos\TipoOrganizacion;
use Illuminate\Database\Seeder;

/**
 * Plantillas de rol del Doc 06 §2. IDEMPOTENTE.
 *
 * Son MOLDES globales. Al crear un tenant se clonan a roles suyos
 * (ClonadorPlantillasRol) y a partir de ahí la organización los edita o los
 * borra: son roles de ejemplo, borrables por diseño. Ningún código debe
 * buscarlos por nombre.
 *
 * El mismo oficio cambia de nombre según el tipo de tenant —orientador en una
 * escuela, reclutador en una empresa— pero lleva los mismos permisos. Por eso
 * el nombre depende del tipo y la lista de permisos no.
 */
class PlantillasRolSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrarUniversales();
        $this->sembrarPorTipo();
    }

    /**
     * Plantillas sin tipo (NULL): aplican a cualquier organización.
     */
    private function sembrarUniversales(): void
    {
        /*
         * Titular y Tutor NO llevan permisos.
         *
         * No es un olvido: su acceso es IMPLÍCITO en AccesoService —el titular
         * sobre sí mismo, el tutor vigente sobre su tutelado— porque la mayoría
         * de las personas del sistema no tienen ningún rol asignado y exigirles
         * uno cerraría el portal de autollenado a todo el mundo. La plantilla
         * existe para poder nombrarlos en la interfaz y darles alcance.
         */
        $this->plantilla(null, 'titular', 'Titular', 1, []);
        $this->plantilla(null, 'tutor', 'Tutor', 1, []);

        $this->plantilla(null, 'auditor', 'Auditor', 1, [
            'bitacora.consultar',
        ]);

        $this->plantilla(null, 'examinador', 'Examinador', 3, [
            'personas.ver',
            'protocolos.capturar',
        ]);
    }

    private function sembrarPorTipo(): void
    {
        /** @var array<string, array{coordinador: string, operativo: string, capturista: string}> $nombres */
        $nombres = [
            'escuela' => [
                'coordinador' => 'Orientador',
                'operativo' => 'Docente',
                'capturista' => 'Capturista de expediente',
            ],
            'empresa' => [
                'coordinador' => 'Reclutador',
                'operativo' => 'Supervisor de línea',
                'capturista' => 'Capturista de expediente',
            ],
            'consultorio' => [
                'coordinador' => 'Coordinador',
                'operativo' => 'Asistente',
                'capturista' => 'Capturista de expediente',
            ],
            'dependencia' => [
                'coordinador' => 'Coordinador',
                'operativo' => 'Supervisor',
                'capturista' => 'Capturista de expediente',
            ],
        ];

        foreach ($nombres as $clave => $etiquetas) {
            $tipo = TipoOrganizacion::query()->where('clave', $clave)->first();

            if ($tipo === null) {
                continue;
            }

            $this->plantilla($tipo->id, 'superadmin', 'Administrador de la organización', 2, [
                'organizacion.configurar',
                'unidades.gestionar',
                'agrupaciones.gestionar',
                'roles.gestionar',
                'personas.ver',
                'personas.crear',
                'personas.vincular',
                'tutorias.validar',
                'instrumentos.habilitar',
                'instrumentos.capturar_contenido',
            ]);

            $this->plantilla($tipo->id, 'coordinador', $etiquetas['coordinador'], 2, [
                'personas.ver',
                'personas.crear',
                'personas.vincular',
                'agrupaciones.gestionar',
                'expediente.ver',
                'evaluaciones.asignar',
                'baterias.armar',
                'resultados.ver_resumen',
                'resultados.ver_detalle',
                'resultados.exportar',
                'reportes.grupales',
            ]);

            /*
             * Nivel 4: es el único rol que alcanza lo clínico. Lleva además la
             * asignación discreta y la validación de reportes de IA, porque el
             * Doc 01 P6 exige que la firma sea un acto profesional humano.
             */
            $this->plantilla($tipo->id, 'psicologo', 'Psicólogo', 4, [
                'personas.ver',
                'personas.crear',
                'personas.vincular',
                'tutorias.validar',
                'expediente.ver',
                'expediente.editar',
                'expediente.validar',
                'evaluaciones.asignar',
                'evaluaciones.asignar_individual_discreta',
                'baterias.armar',
                'protocolos.capturar',
                'resultados.ver_resumen',
                'resultados.ver_detalle',
                'resultados.exportar',
                'reportes.grupales',
                'interpretaciones.editar',
                'ia.validar_reportes',
                'alertas.atender',
            ]);

            // Sólo resumen, y sólo de su agrupación: el alcance lo acota, no
            // el permiso.
            $this->plantilla($tipo->id, 'operativo', $etiquetas['operativo'], 1, [
                'personas.ver',
                'resultados.ver_resumen',
            ]);

            $this->plantilla($tipo->id, 'capturista', $etiquetas['capturista'], 1, [
                'personas.ver',
                'expediente.ver',
                'expediente.editar',
                'expediente.validar',
            ]);
        }
    }

    /**
     * @param  list<string>  $permisos
     */
    private function plantilla(
        ?int $tipoOrganizacionId,
        string $clave,
        string $nombre,
        int $nivelMaximo,
        array $permisos,
    ): void {
        $plantilla = PlantillaRol::query()->updateOrCreate(
            ['tipo_organizacion_id' => $tipoOrganizacionId, 'clave' => $clave],
            ['nombre' => $nombre, 'nivel_sensibilidad_max' => $nivelMaximo]
        );

        /*
         * Se re-siembra la lista completa: `updateOrCreate` por permiso dejaría
         * vivos los que se hayan retirado de la plantilla, y una plantilla que
         * conserva permisos borrados los clonaría al siguiente tenant.
         */
        $plantilla->permisos()->delete();

        foreach ($permisos as $permiso) {
            $plantilla->permisos()->create(['permiso' => $permiso]);
        }
    }
}
