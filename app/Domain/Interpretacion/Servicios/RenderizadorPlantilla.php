<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

/**
 * Sustituye los marcadores de una plantilla de reporte.
 *
 * NO COMPILA NADA. Una plantilla que un tenant puede editar y que el servidor
 * compilara —Blade, Twig, lo que sea— es ejecución de código arbitrario con los
 * pasos intermedios ya hechos: quien pueda editar la plantilla del reporte
 * podría leer el `.env`.
 *
 * La gramática es deliberadamente pobre y no va a crecer:
 *
 *   {{ clave }}            un valor
 *   {{#lista}} … {{/lista}} repetir un bloque por cada elemento
 *
 * Todo lo que se sustituye va ESCAPADO. Un nombre con `<script>` dentro llega
 * al HTML del reporte, y el reporte se abre en un navegador.
 */
class RenderizadorPlantilla
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function render(string $plantilla, array $datos): string
    {
        $salida = $this->expandirBloques($plantilla, $datos);

        return $this->sustituirValores($salida, $datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function expandirBloques(string $plantilla, array $datos): string
    {
        return (string) preg_replace_callback(
            '/\{\{#([a-z_][a-z0-9_]*)\}\}(.*?)\{\{\/\1\}\}/s',
            function (array $coincidencia) use ($datos): string {
                $lista = $datos[$coincidencia[1]] ?? null;

                if (! is_array($lista)) {
                    return '';
                }

                $partes = [];

                foreach ($lista as $elemento) {
                    if (! is_array($elemento)) {
                        continue;
                    }

                    // Recursivo: un bloque puede llevar bloques dentro, que es
                    // lo que hace posible "dominios → constructos".
                    $partes[] = $this->render($coincidencia[2], $elemento);
                }

                return implode('', $partes);
            },
            $plantilla,
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function sustituirValores(string $plantilla, array $datos): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/',
            static function (array $coincidencia) use ($datos): string {
                $valor = $datos[$coincidencia[1]] ?? '';

                if (is_array($valor) || is_object($valor)) {
                    return '';
                }

                if (is_bool($valor)) {
                    return $valor ? 'sí' : 'no';
                }

                /*
                 * ESCAPADO siempre. Un nombre con `<script>` dentro llega al
                 * HTML del reporte, y el reporte se abre en un navegador. No
                 * hay marcador "sin escapar" a propósito: en cuanto exista,
                 * alguien lo va a usar para meter estilos y con ellos scripts.
                 */
                return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $plantilla,
        );
    }
}
