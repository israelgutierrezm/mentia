<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accesos\Modelos\NivelSensibilidad;
use App\Domain\Expedientes\Modelos\CatalogoOpciones;
use App\Domain\Expedientes\Modelos\ExpedienteCampo;
use App\Domain\Expedientes\Modelos\OpcionCatalogo;
use App\Domain\Expedientes\Modelos\SeccionExpediente;
use App\Domain\Expedientes\Modelos\TipoDocumento;
use Illuminate\Database\Seeder;

/**
 * Estructura base del expediente. IDEMPOTENTE.
 *
 * Es CONFIGURACIÓN, no código: agregar un campo aquí es una fila, y una
 * organización que necesite otro no requiere migración. Lo que se siembra es
 * el mínimo común —lo que cualquier tenant necesita— no el expediente completo
 * de nadie.
 */
class ExpedienteSeeder extends Seeder
{
    public function run(): void
    {
        $this->catalogos();
        $this->secciones();
        $this->tiposDocumento();
    }

    private function catalogos(): void
    {
        $catalogos = [
            'escolaridad' => [
                'nombre' => 'Escolaridad',
                'opciones' => [
                    'preescolar' => 'Preescolar',
                    'primaria' => 'Primaria',
                    'secundaria' => 'Secundaria',
                    'bachillerato' => 'Bachillerato',
                    'licenciatura' => 'Licenciatura',
                    'posgrado' => 'Posgrado',
                ],
            ],
            'estado_civil' => [
                'nombre' => 'Estado civil',
                'opciones' => [
                    'soltero' => 'Soltero(a)',
                    'casado' => 'Casado(a)',
                    'union_libre' => 'Unión libre',
                    'divorciado' => 'Divorciado(a)',
                    'viudo' => 'Viudo(a)',
                ],
            ],
            'tipo_sangre' => [
                'nombre' => 'Tipo de sangre',
                'opciones' => [
                    'o_positivo' => 'O+', 'o_negativo' => 'O−',
                    'a_positivo' => 'A+', 'a_negativo' => 'A−',
                    'b_positivo' => 'B+', 'b_negativo' => 'B−',
                    'ab_positivo' => 'AB+', 'ab_negativo' => 'AB−',
                ],
            ],
        ];

        foreach ($catalogos as $clave => $datos) {
            $catalogo = CatalogoOpciones::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $datos['nombre']]
            );

            $orden = 0;

            foreach ($datos['opciones'] as $claveOpcion => $etiqueta) {
                OpcionCatalogo::query()->updateOrCreate(
                    ['catalogo_opciones_id' => $catalogo->id, 'clave' => $claveOpcion],
                    ['etiqueta' => $etiqueta, 'orden' => $orden++, 'activo' => true]
                );
            }
        }
    }

    private function secciones(): void
    {
        /** @var array<int, int> $niveles nivel => id */
        $niveles = NivelSensibilidad::query()->pluck('id', 'nivel')->all();

        $secciones = [
            [
                'clave' => 'datos_generales',
                'nombre' => 'Datos generales',
                'orden' => 10,
                'nivel' => 1,
                'campos' => [
                    ['telefono', 'Teléfono de contacto', 'texto', 'titular', 1],
                    ['correo', 'Correo electrónico', 'texto', 'titular', 1],
                    ['domicilio', 'Domicilio', 'texto', 'titular', 1],
                    ['escolaridad', 'Escolaridad', 'catalogo', 'titular', 1, 'escolaridad'],
                    ['estado_civil', 'Estado civil', 'catalogo', 'titular', 1, 'estado_civil'],
                ],
            ],
            [
                'clave' => 'contacto_emergencia',
                'nombre' => 'Contacto de emergencia',
                'orden' => 20,
                'nivel' => 1,
                'campos' => [
                    ['emergencia_nombre', 'Nombre de quien contactar', 'texto', 'titular', 1],
                    ['emergencia_telefono', 'Teléfono de emergencia', 'texto', 'titular', 1],
                    ['emergencia_parentesco', 'Parentesco', 'texto', 'titular', 1],
                ],
            ],
            [
                'clave' => 'medico_relevante',
                'nombre' => 'Antecedentes médicos relevantes',
                'orden' => 30,

                /*
                 * Nivel 3, no 1. Un antecedente médico no es un dato de
                 * contacto: es lo que un reclutador NO debe ver aunque tenga
                 * `expediente.ver` (Doc 06 §3, no discriminación).
                 */
                'nivel' => 3,
                'campos' => [
                    ['tipo_sangre', 'Tipo de sangre', 'catalogo', 'titular', 1, 'tipo_sangre'],
                    ['alergias', 'Alergias', 'texto', 'titular', 3],
                    ['medicacion_actual', 'Medicación actual', 'texto', 'profesional', 3],
                    ['antecedentes', 'Antecedentes relevantes', 'texto', 'profesional', 3],
                ],
            ],
            [
                'clave' => 'legal',
                'nombre' => 'Información legal',
                'orden' => 40,
                'nivel' => 2,
                'campos' => [
                    ['tutela_observaciones', 'Observaciones sobre tutela', 'texto', 'admin', 2],
                ],
            ],
            [
                'clave' => 'documentos',
                'nombre' => 'Documentos',
                'orden' => 50,
                'nivel' => 1,
                'campos' => [],
            ],
            [
                'clave' => 'notas_profesionales',
                'nombre' => 'Notas profesionales',
                'orden' => 60,
                'nivel' => 4,
                'campos' => [],
            ],
        ];

        foreach ($secciones as $datos) {
            $seccion = SeccionExpediente::query()->updateOrCreate(
                ['clave' => $datos['clave']],
                [
                    'nombre' => $datos['nombre'],
                    'orden' => $datos['orden'],
                    'nivel_sensibilidad_id' => $niveles[$datos['nivel']],
                ]
            );

            $orden = 0;

            foreach ($datos['campos'] as $campo) {
                [$clave, $etiqueta, $tipo, $quien, $nivel] = $campo;
                $catalogoClave = $campo[5] ?? null;

                ExpedienteCampo::query()->updateOrCreate(
                    ['seccion_id' => $seccion->id, 'clave' => $clave],
                    [
                        'etiqueta' => $etiqueta,
                        'tipo_dato' => $tipo,
                        'catalogo_opciones_id' => $catalogoClave === null
                            ? null
                            : CatalogoOpciones::query()->where('clave', $catalogoClave)->value('id'),
                        'obligatorio' => false,
                        'quien_puede_llenar' => $quien,
                        'nivel_sensibilidad_id' => $niveles[$nivel],
                        'orden' => $orden += 10,
                        'activo' => true,
                    ]
                );
            }
        }
    }

    private function tiposDocumento(): void
    {
        $tipos = [
            ['acta_nacimiento', 'Acta de nacimiento', true],
            ['identificacion_oficial', 'Identificación oficial', true],
            ['comprobante_domicilio', 'Comprobante de domicilio', true],
            ['constancia_medica', 'Constancia médica', true],
            ['acreditacion_tutela', 'Acreditación de tutela', true],
            ['consentimiento_firmado', 'Consentimiento firmado', false],
        ];

        foreach ($tipos as [$clave, $nombre, $requiereValidacion]) {
            TipoDocumento::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'requiere_validacion' => $requiereValidacion]
            );
        }
    }
}
