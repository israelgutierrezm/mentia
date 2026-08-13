<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Consentimientos\Modelos\TextoConsentimiento;
use App\Domain\Consentimientos\Modelos\TipoConsentimiento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Tipos de consentimiento y textos base de PLATAFORMA. IDEMPOTENTE.
 *
 * Los textos que se siembran son un punto de partida operativo, NO un aviso de
 * privacidad revisado por abogado. Cada tenant publica el suyo —es el
 * responsable del tratamiento (Doc 06 §3)— y esta plantilla sólo evita que el
 * sistema arranque sin nada que enseñarle a la persona.
 */
class ConsentimientosSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [TipoConsentimiento::TRATAMIENTO, 'Tratamiento de datos personales sensibles',
                'Autoriza el tratamiento de datos de salud mental y resultados psicométricos.'],
            [TipoConsentimiento::EDUCATIVA, 'Aplicación educativa',
                'Evaluaciones dentro de un contexto escolar.'],
            [TipoConsentimiento::LABORAL, 'Aplicación laboral',
                'Evaluaciones dentro de un proceso de selección o desarrollo.'],
            [TipoConsentimiento::CLINICA, 'Aplicación clínica',
                'Instrumentos de tamizaje y seguimiento clínico breve.'],
            [TipoConsentimiento::COMPARTICION, 'Compartición entre organizaciones',
                'Permite que otra organización vea parte del expediente.'],
            [TipoConsentimiento::CONTACTO, 'Contacto',
                'Autoriza el envío de notificaciones y recordatorios.'],
        ];

        foreach ($tipos as [$clave, $nombre, $descripcion]) {
            TipoConsentimiento::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'descripcion' => $descripcion]
            );
        }

        $this->textoDePlataforma(
            TipoConsentimiento::TRATAMIENTO,
            'Aviso de privacidad y consentimiento para el tratamiento de datos sensibles',
            <<<'TEXTO'
            Los resultados de evaluaciones psicométricas, así como la información de salud
            mental que se registre en tu expediente, son datos personales sensibles conforme
            a la Ley Federal de Protección de Datos Personales en Posesión de los
            Particulares.

            Al aceptar, autorizas de manera expresa que se traten para las finalidades de
            evaluación, interpretación y seguimiento que la organización responsable te haya
            informado.

            Puedes revocar este consentimiento en cualquier momento desde tu portal. La
            revocación surte efecto de inmediato hacia adelante y no alcanza a los
            tratamientos ya realizados.

            Puedes ejercer tus derechos de acceso, rectificación, cancelación y oposición
            (ARCO) desde el mismo portal.
            TEXTO
        );

        $this->textoDePlataforma(
            TipoConsentimiento::COMPARTICION,
            'Consentimiento para compartir tu expediente con otra organización',
            <<<'TEXTO'
            Tu expediente psicométrico te pertenece y te acompaña entre organizaciones.
            Ninguna organización ve lo que otra registró, salvo que tú lo autorices.

            Al aceptar, permites que la organización que elijas consulte la parte de tu
            historial que tú determines, con el alcance y la vigencia que indiques.

            Puedes revocar esta compartición en cualquier momento; al hacerlo, la
            organización deja de tener acceso de inmediato.
            TEXTO
        );
    }

    private function textoDePlataforma(string $claveTipo, string $titulo, string $cuerpo): void
    {
        $tipo = TipoConsentimiento::query()->where('clave', $claveTipo)->firstOrFail();

        $cuerpo = trim($cuerpo);

        /*
         * `firstOrCreate` y no `updateOrCreate`: un texto publicado es
         * inmutable (el modelo lo impide), y re-sembrar no debe intentar
         * pisarlo. Si el texto cambia, se siembra una versión nueva.
         */
        TextoConsentimiento::query()->firstOrCreate(
            [
                'organizacion_id' => null,
                'tipo_consentimiento_id' => $tipo->id,
                'version' => 1,
            ],
            [
                'titulo' => $titulo,
                'cuerpo' => $cuerpo,
                'hash_sha256' => TextoConsentimiento::hashDe($cuerpo),
                'vigente_desde' => Carbon::now()->toDateString(),
            ]
        );
    }
}
