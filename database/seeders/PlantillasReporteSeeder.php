<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Interpretacion\Modelos\PlantillaReporte;
use Illuminate\Database\Seeder;

/**
 * Las plantillas de reporte DEL SISTEMA (`organizacion_id` NULL).
 *
 * Existen para que ningún tenant se quede sin poder generar un reporte el día
 * uno. Quien quiera el suyo con su logotipo crea una propia y la resolución la
 * prefiere; la del sistema es el piso, no el techo.
 *
 * Son HTML con marcadores, no plantillas Blade: el renderizador sustituye y
 * escapa, nunca compila. Una plantilla que un tenant puede editar y que el
 * servidor compilara sería ejecución de código arbitrario.
 *
 * Idempotente: se puede correr mil veces.
 */
class PlantillasReporteSeeder extends Seeder
{
    public function run(): void
    {
        $this->individual('profesional', $this->individualProfesional());
        $this->individual('evaluado_adulto', $this->individualEvaluado());
        $this->individual('tutor', $this->individualEvaluado());

        $this->crear('longitudinal', 'profesional', $this->longitudinal());
        $this->crear('integrador', 'profesional', $this->integrador());
        $this->crear('grupal', 'profesional', $this->grupal());
        $this->crear('nom035', 'profesional', $this->nom035());
    }

    private function individual(string $audiencia, string $html): void
    {
        $this->crear('individual', $audiencia, $html);
    }

    private function crear(string $tipo, string $audiencia, string $html): void
    {
        PlantillaReporte::query()->updateOrCreate(
            [
                'organizacion_id' => null,
                'tipo' => $tipo,
                'audiencia' => $audiencia,
                'version_instrumento_id' => null,
                'bateria_id' => null,
            ],
            ['estructura_html' => $html, 'vigente' => true],
        );
    }

    private function estilos(): string
    {
        return <<<'CSS'
            <style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1e293b; }
                h1 { font-size: 16pt; margin-bottom: 2px; }
                h2 { font-size: 12pt; margin-top: 18px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
                .meta { color: #64748b; font-size: 9pt; }
                table { width: 100%; border-collapse: collapse; margin-top: 6px; }
                th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; }
                th { color: #475569; font-weight: 600; }
                .aviso { background: #fef3c7; padding: 8px; margin: 10px 0; font-size: 10pt; }
                .pie { margin-top: 26px; color: #64748b; font-size: 9pt; border-top: 1px solid #cbd5e1; padding-top: 8px; }

                /*
                 * El perfil gráfico. Barras con `width` en porcentaje y no un
                 * SVG: dompdf dibuja cajas de forma fiable y el soporte de SVG
                 * es parcial, y un reporte que se ve bien en el navegador y
                 * roto en el PDF es peor que uno sin gráfica.
                 *
                 * Sólo se pinta barra cuando hay norma. Un bruto dibujado junto
                 * a percentiles se lee como si significara lo mismo, y no
                 * significa nada fuera de su propia aplicación.
                 */
                .barra { background: #e2e8f0; height: 9px; width: 100%; margin-top: 2px; }
                .barra div { background: #2563eb; height: 9px; }
                .sinnorma { color: #b45309; font-size: 9pt; }
            </style>
            CSS;
    }

    /**
     * El reporte técnico. Lleva puntajes, y por eso su audiencia es
     * `profesional`: los números sin nadie que los explique son una cifra que
     * la persona va a interpretar sola, y casi siempre mal.
     */
    private function individualProfesional(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>{{ instrumento }}</h1>
            <p class="meta">{{ persona }} · Aplicado el {{ fecha }} · Generado el {{ generado_en }}</p>

            <div class="aviso">Validez del protocolo: {{ validez }}.</div>

            <h2>Perfil por escalas</h2>
            <table>
                <tr><th style="width:38%">Escala</th><th style="width:14%">Bruto</th><th>Perfil</th></tr>
                {{#escalas}}
                <tr>
                    <td>{{ nombre }}</td>
                    <td>{{ bruto }}</td>
                    <td>
                        {{ normalizado }} {{ tipo_norma }} {{ etiqueta }}
                        <div class="barra"><div style="width: {{ ancho_pct }}%"></div></div>
                        <span class="sinnorma">{{ aviso_sin_norma }}</span>
                    </td>
                </tr>
                {{/escalas}}
            </table>

            <h2>Interpretación</h2>
            {{#interpretaciones}}
            <p>{{ texto }}</p>
            {{/interpretaciones}}

            <p class="pie">
                Este reporte es un insumo profesional. El diagnóstico y la
                responsabilidad clínica son actos de la persona que lo firma.
            </p>
            HTML;
    }

    /**
     * El de quien contestó. SIN puntajes: sólo el texto escrito para él.
     */
    private function individualEvaluado(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>Resultados de tu evaluación</h1>
            <p class="meta">{{ instrumento }} · {{ fecha }}</p>

            {{#interpretaciones}}
            <p>{{ texto }}</p>
            {{/interpretaciones}}

            <p class="pie">
                Si algo de esto te genera dudas, coméntalo con la persona que te
                aplicó la evaluación. Estos resultados no son un diagnóstico.
            </p>
            HTML;
    }

    private function longitudinal(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>Perfil longitudinal</h1>
            <p class="meta">{{ persona }} · Generado el {{ generado_en }}</p>

            {{#constructos}}
            <h2>{{ constructo }}</h2>
            <table>
                <tr><th>Fecha</th><th>Norma</th><th>Valor</th><th>Bandera</th></tr>
                {{#puntos}}
                <tr>
                    <td>{{ fecha }}</td>
                    <td>{{ tipo_norma }}</td>
                    <td>{{ valor }}</td>
                    <td>{{ bandera }}</td>
                </tr>
                {{/puntos}}
            </table>
            {{/constructos}}

            <p class="pie">
                Sólo aparecen las mediciones con baremo aplicable. Las series de
                distinta norma no son comparables entre sí.
            </p>
            HTML;
    }

    /**
     * El grupal NO lista personas, ni siquiera cuando la asignación es nominal.
     * Existe para ver el bosque; quien necesite el árbol abre el resultado
     * individual, que pasa por AccesoService uno por uno.
     */
    private function grupal(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>Reporte grupal</h1>
            <p class="meta">Folio {{ folio }} · {{ contestadas }} evaluaciones contestadas · {{ generado_en }}</p>

            {{#escalas}}
            <h2>{{ nombre }}</h2>
            <table>
                <tr><th>n</th><th>Media</th><th>Mediana</th><th>Mínimo</th><th>Máximo</th></tr>
                <tr>
                    <td>{{ n }}</td>
                    <td>{{ media }}</td>
                    <td>{{ mediana }}</td>
                    <td>{{ minimo }}</td>
                    <td>{{ maximo }}</td>
                </tr>
            </table>
            {{/escalas}}

            <p class="pie">
                Este reporte no identifica a ninguna persona. Los resultados
                individuales se consultan uno por uno, con su propio registro de
                acceso.
            </p>
            HTML;
    }

    /**
     * NOM-035 por centro de trabajo.
     *
     * Es el mismo agregado con otra portada, y eso es a propósito: los cortes y
     * los semáforos oficiales los aplica el pipeline con `nom035_cortes`, no la
     * plantilla. Una plantilla que calculara sus propios niveles produciría un
     * documento con formato oficial y números que no lo son.
     */
    private function nom035(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>NOM-035-STPS-2018</h1>
            <p class="meta">
                Resultados por centro de trabajo · Folio {{ folio }} ·
                {{ contestadas }} cuestionarios · {{ generado_en }}
            </p>

            <div class="aviso">
                Documento de evidencia. Consérvese conforme al plazo que fija la
                norma.
            </div>

            {{#escalas}}
            <h2>{{ nombre }}</h2>
            <table>
                <tr><th>Cuestionarios</th><th>Media</th><th>Mínimo</th><th>Máximo</th></tr>
                <tr>
                    <td>{{ n }}</td>
                    <td>{{ media }}</td>
                    <td>{{ minimo }}</td>
                    <td>{{ maximo }}</td>
                </tr>
            </table>
            {{/escalas}}

            <p class="pie">
                Los niveles de riesgo se calculan con los cortes publicados en el
                DOF. Este agregado no permite identificar a ninguna persona.
            </p>
            HTML;
    }

    private function integrador(): string
    {
        return $this->estilos().<<<'HTML'
            <h1>Reporte integrador</h1>
            <p class="meta">{{ persona }} · Generado el {{ generado_en }}</p>

            <div class="aviso">
                Borrador redactado por {{ modelo }} — estado: {{ estado }}.
                Requiere revisión, corrección y firma profesional.
            </div>

            <p>{{ texto }}</p>

            <p class="pie">
                La redacción automática integra interpretaciones ya resueltas por
                el motor de calificación. No califica, no diagnostica y no
                sustituye el juicio de quien firma.
            </p>
            HTML;
    }
}
