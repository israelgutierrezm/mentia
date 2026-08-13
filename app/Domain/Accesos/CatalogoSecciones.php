<?php

declare(strict_types=1);

namespace App\Domain\Accesos;

use App\Domain\Accesos\Datos\Seccion;
use App\Domain\Personas\Modelos\Persona;

/**
 * Las secciones del sistema, DECLARADAS con su permiso.
 *
 * El menú y el panel se arman filtrando esta lista por lo que el rol activo
 * puede hacer. No hay ramas por rol en ninguna parte: una organización que
 * define un rol nuevo con otra combinación de permisos ve el menú que le
 * corresponde sin que nadie toque el frontend.
 *
 * Es la contraparte de `CatalogoPermisos`: aquel dice qué se puede hacer, éste
 * dónde se hace.
 */
class CatalogoSecciones
{
    /**
     * @return list<Seccion>
     */
    public function todas(): array
    {
        return [
            new Seccion(
                'personas',
                'Personas',
                '/personas',
                'personas.ver',
                'Padrón de la organización, fichas y expedientes.',
                'Personas',
            ),
            new Seccion(
                'tutorias',
                'Tutorías',
                '/tutorias',
                'tutorias.validar',
                'Vínculos con menores pendientes de acreditar.',
                'Personas',
            ),

            new Seccion(
                'unidades',
                'Unidades',
                '/unidades',
                'unidades.gestionar',
                'La estructura de la organización.',
                'Organización',
            ),
            new Seccion(
                'agrupaciones',
                'Agrupaciones',
                '/agrupaciones',
                'agrupaciones.gestionar',
                'Grupos, generaciones y áreas con sus miembros.',
                'Organización',
            ),

            new Seccion(
                'catalogo',
                'Catálogo',
                '/catalogo',
                null,
                'Los instrumentos disponibles y sus versiones.',
                'Instrumentos',
            ),
            new Seccion(
                'habilitacion',
                'Habilitación',
                '/habilitacion',
                'instrumentos.habilitar',
                'Qué instrumentos puede aplicar esta organización.',
                'Instrumentos',
            ),
            new Seccion(
                'baterias',
                'Baterías',
                '/baterias',
                'baterias.armar',
                'Conjuntos de instrumentos que se aplican juntos.',
                'Instrumentos',
            ),

            new Seccion(
                'captura-protocolo',
                'Captura de protocolo',
                '/captura-protocolo',
                'protocolos.capturar',
                'Registrar resultados de pruebas aplicadas en papel.',
                'Evaluación',
            ),

            /*
             * Las alertas llevan CONTADOR. Es la única sección donde el número
             * importa más que la etiqueta: una bandeja con tres alertas
             * críticas abiertas tiene que verse desde cualquier pantalla.
             */
            new Seccion(
                'alertas',
                'Alertas',
                '/alertas',
                'alertas.atender',
                'Riesgos detectados que esperan atención.',
                'Evaluación',
                conContador: true,
            ),

            new Seccion(
                'roles',
                'Roles y permisos',
                '/roles',
                'roles.gestionar',
                'Quién puede hacer qué dentro de la organización.',
                'Administración',
            ),
            new Seccion(
                'alcances',
                'Alcances',
                '/alcances',
                'roles.gestionar',
                'Hasta dónde llega cada rol asignado.',
                'Administración',
            ),
        ];
    }

    /**
     * Las que esta persona alcanza.
     *
     * Una sección sin permiso declarado la ve todo el mundo con sesión: el
     * catálogo de instrumentos es información de referencia, no datos de nadie.
     *
     * @return list<Seccion>
     */
    public function para(Persona $persona): array
    {
        return array_values(array_filter(
            $this->todas(),
            static fn (Seccion $seccion): bool => $seccion->permiso === null
                || $persona->hasPermissionTo($seccion->permiso, 'web'),
        ));
    }
}
