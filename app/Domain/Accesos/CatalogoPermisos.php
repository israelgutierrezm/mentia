<?php

declare(strict_types=1);

namespace App\Domain\Accesos;

use App\Domain\Accesos\Datos\Permiso;

/**
 * Los permisos del sistema, declarados en CÓDIGO (Doc 03 §M3: "los permisos
 * son catálogo fijo del sistema").
 *
 * No se crean desde pantalla y no son configurables por el tenant: un permiso
 * es una llave que el código consulta, y una llave que nadie consulta no
 * protege nada. Lo que el tenant sí configura son sus ROLES —qué permisos
 * lleva cada uno—, y eso vive en la base.
 *
 * Agregar un permiso aquí y sembrarlo es lo que lo vuelve asignable; retirarlo
 * exige revisar antes qué roles lo traen.
 */
class CatalogoPermisos
{
    /**
     * @return list<Permiso>
     */
    public static function todos(): array
    {
        return [
            // ── Organización ──────────────────────────────────────────────
            new Permiso(
                'organizacion.configurar',
                'organizacion',
                'Configurar la organización',
                'Datos generales, vocabulario y parámetros de operación.'
            ),
            new Permiso(
                'unidades.gestionar',
                'organizacion',
                'Gestionar unidades',
                'Alta, edición y jerarquía de planteles, sedes, departamentos y áreas.'
            ),
            new Permiso(
                'agrupaciones.gestionar',
                'organizacion',
                'Gestionar agrupaciones',
                'Alta y edición de grupos, vacantes, cohortes y centros de trabajo, y sus miembros.'
            ),
            new Permiso(
                'roles.gestionar',
                'organizacion',
                'Gestionar roles y alcances',
                'Definir roles de la organización, sus permisos, su tope de sensibilidad y a quién se asignan.'
            ),

            // ── Personas ──────────────────────────────────────────────────
            new Permiso(
                'personas.ver',
                'personas',
                'Ver personas',
                'Consultar el padrón de personas vinculadas a la organización.'
            ),
            new Permiso(
                'personas.crear',
                'personas',
                'Dar de alta personas',
                'Registrar personas nuevas con verificación de identidad.'
            ),
            new Permiso(
                'personas.vincular',
                'personas',
                'Vincular personas',
                'Ligar a la organización una persona que ya existe en la plataforma.'
            ),
            new Permiso(
                'tutorias.validar',
                'personas',
                'Validar tutorías',
                'Acreditar que quien dice ser tutor de un menor lo es. Sin validación no hay acceso.'
            ),

            // ── Expediente ────────────────────────────────────────────────
            new Permiso(
                'expediente.ver',
                'expediente',
                'Ver expediente',
                'Consultar las secciones del expediente hasta el nivel de sensibilidad del rol.'
            ),
            new Permiso(
                'expediente.editar',
                'expediente',
                'Editar expediente',
                'Capturar y corregir valores del expediente.'
            ),
            new Permiso(
                'expediente.validar',
                'expediente',
                'Validar expediente',
                'Dar por buenos los valores capturados por el titular o el tutor.'
            ),

            // ── Evaluaciones ──────────────────────────────────────────────
            new Permiso(
                'evaluaciones.asignar',
                'evaluaciones',
                'Asignar evaluaciones',
                'Lanzar asignaciones individuales, grupales y campañas.'
            ),
            new Permiso(
                'evaluaciones.asignar_individual_discreta',
                'evaluaciones',
                'Asignar de forma discreta',
                'Asignaciones cuya existencia sólo ve quien las creó. Uso clínico.'
            ),
            new Permiso(
                'baterias.armar',
                'evaluaciones',
                'Armar baterías',
                'Componer y editar baterías de instrumentos.'
            ),
            new Permiso(
                'protocolos.capturar',
                'evaluaciones',
                'Capturar protocolos',
                'Registrar resultados de instrumentos de aplicación presencial.'
            ),

            // ── Resultados ────────────────────────────────────────────────
            new Permiso(
                'resultados.ver_resumen',
                'resultados',
                'Ver resumen de resultados',
                'Vista sintética, sin detalle técnico ni puntajes por reactivo.'
            ),
            new Permiso(
                'resultados.ver_detalle',
                'resultados',
                'Ver detalle de resultados',
                'Puntajes, percentiles y perfil técnico, hasta el nivel de sensibilidad del rol.'
            ),
            new Permiso(
                'resultados.exportar',
                'resultados',
                'Exportar resultados',
                'Descargar resultados y reportes. Cada descarga queda en bitácora.'
            ),
            new Permiso(
                'reportes.grupales',
                'resultados',
                'Ver reportes grupales',
                'Distribuciones y semáforos por agrupación o centro de trabajo.'
            ),
            new Permiso(
                'interpretaciones.editar',
                'resultados',
                'Editar interpretaciones',
                'Ajustar los textos de interpretación de la organización.'
            ),
            new Permiso(
                'ia.validar_reportes',
                'resultados',
                'Validar reportes de IA',
                'Revisar y firmar el borrador que redacta la IA. Sin esta firma no se libera.'
            ),

            // ── Alertas ───────────────────────────────────────────────────
            new Permiso(
                'alertas.atender',
                'alertas',
                'Atender alertas',
                'Recibir y cerrar alertas de reactivos centinela, con resolución documentada.'
            ),

            // ── Catálogo ──────────────────────────────────────────────────
            new Permiso(
                'instrumentos.habilitar',
                'catalogo',
                'Habilitar instrumentos',
                'Activar instrumentos para la organización y declarar su licencia.'
            ),
            new Permiso(
                'instrumentos.capturar_contenido',
                'catalogo',
                'Capturar contenido de instrumentos',
                'Cargar reactivos de instrumentos licenciados por la organización.'
            ),

            // ── Auditoría ─────────────────────────────────────────────────
            new Permiso(
                'bitacora.consultar',
                'auditoria',
                'Consultar la bitácora',
                'Ver quién accedió a qué, cuándo y con qué propósito.'
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function claves(): array
    {
        return array_map(
            static fn (Permiso $permiso): string => $permiso->clave,
            self::todos()
        );
    }

    public static function existe(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }
}
