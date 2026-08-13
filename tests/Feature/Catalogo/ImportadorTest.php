<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use App\Domain\Catalogo\Modelos\Instrumento;
use App\Domain\Catalogo\Modelos\Reactivo;
use App\Domain\Catalogo\Servicios\ImportadorInstrumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportadorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /**
     * Escribe un .xlsx con las hojas que se le den.
     *
     * @param  array<string, list<list<string>>>  $hojas
     */
    private function plantilla(array $hojas): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        foreach ($hojas as $nombre => $filas) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($nombre);

            foreach ($filas as $indice => $fila) {
                $hoja->fromArray($fila, null, 'A'.($indice + 1));
            }
        }

        $ruta = sys_get_temp_dir().'/mentia_'.uniqid().'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * @return array<string, list<list<string>>>
     */
    private function plantillaValida(): array
    {
        return [
            'instrumento' => [
                ['clave', 'nombre', 'dominio', 'nivel_sensibilidad', 'version', 'estatus_licencia', 'contenido_incluido', 'modo_calificacion', 'quien_responde'],
                ['tamiz_demo', 'Tamizaje demostrativo', 'emocional', '3', '1.0', 'dominio_publico', 'completo', 'algoritmica', 'autoaplicada'],
            ],
            'escalas' => [
                ['clave', 'nombre', 'orden', 'es_validez', 'escala_padre'],
                ['TOTAL', 'Puntaje total', '1', '', ''],
            ],
            'bloques' => [
                ['clave', 'titulo', 'orden', 'instrucciones'],
                ['B1', 'Bloque único', '1', 'Responde con qué frecuencia te ocurre.'],
            ],
            'reactivos' => [
                ['codigo', 'bloque', 'tipo', 'enunciado', 'orden', 'es_inverso', 'es_centinela'],
                ['R01', 'B1', 'likert_4', 'Enunciado uno del instrumento demostrativo.', '1', '', ''],
                ['R02', 'B1', 'likert_4', 'Enunciado dos del instrumento demostrativo.', '2', '', 'si'],
            ],
            'opciones' => [
                ['reactivo', 'codigo', 'texto', 'orden'],
                ['R01', '0', 'Nunca', '0'],
                ['R01', '1', 'Casi siempre', '1'],
                ['R02', '0', 'Nunca', '0'],
                ['R02', '1', 'Casi siempre', '1'],
            ],
            'claves' => [
                ['reactivo', 'opcion', 'escala', 'peso', 'rol'],
                ['R01', '0', 'TOTAL', '0', 'normal'],
                ['R01', '1', 'TOTAL', '1', 'normal'],
                ['R02', '0', 'TOTAL', '0', 'normal'],
                ['R02', '1', 'TOTAL', '1', 'normal'],
            ],
            'baremos' => [
                ['escala', 'poblacion', 'tipo_norma', 'bruto_min', 'bruto_max', 'valor_normalizado', 'etiqueta'],
                ['TOTAL', 'general_mx', 'semaforo', '0', '1', '1', 'sin riesgo'],
                ['TOTAL', 'general_mx', 'semaforo', '2', '2', '2', 'riesgo'],
            ],
            'interpretaciones' => [
                ['escala', 'tipo_regla', 'tipo_puntaje', 'operador', 'valor_min', 'valor_max', 'audiencia', 'texto', 'bandera', 'prioridad'],
                ['TOTAL', 'rango_escala', 'bruto', 'entre', '0', '1', 'profesional', 'Perfil compatible con ausencia de sintomatología relevante.', 'verde', '1'],
                ['TOTAL', 'rango_escala', 'bruto', 'entre', '2', '2', 'profesional', 'Se sugiere valoración por profesional de salud mental.', 'rojo', '1'],
            ],
        ];
    }

    public function test_importa_un_instrumento_completo(): void
    {
        $reporte = app(ImportadorInstrumento::class)->importar(
            $this->plantilla($this->plantillaValida())
        );

        $this->assertFalse($reporte->tieneErrores(), print_r($reporte->errores(), true));

        $instrumento = Instrumento::query()->where('clave', 'tamiz_demo')->first();

        $this->assertNotNull($instrumento);
        $this->assertSame(2, $reporte->creados()['reactivos'] ?? 0);
        $this->assertSame(4, $reporte->creados()['opciones'] ?? 0);
        $this->assertSame(4, $reporte->creados()['claves'] ?? 0);
        $this->assertSame(2, $reporte->creados()['interpretaciones'] ?? 0);

        // El centinela llegó marcado: es lo que dispara la alerta síncrona.
        $this->assertTrue(
            Reactivo::query()->where('codigo', 'R02')->value('es_centinela')
        );
    }

    public function test_el_reporte_dice_la_hoja_y_la_fila_del_error(): void
    {
        $hojas = $this->plantillaValida();

        // Fila 3 de `claves`: cita una escala que no existe.
        $hojas['claves'][2][2] = 'NO_EXISTE';

        $reporte = app(ImportadorInstrumento::class)->importar($this->plantilla($hojas));

        $this->assertTrue($reporte->tieneErrores());

        $errores = $reporte->porHoja();

        $this->assertArrayHasKey('claves', $errores);
        $this->assertSame(3, $errores['claves'][0]['fila']);
        $this->assertSame('escala', $errores['claves'][0]['columna']);
        $this->assertStringContainsString('NO_EXISTE', $errores['claves'][0]['mensaje']);
    }

    public function test_un_solo_error_deshace_toda_la_importacion(): void
    {
        $hojas = $this->plantillaValida();
        $hojas['claves'][2][2] = 'NO_EXISTE';

        app(ImportadorInstrumento::class)->importar($this->plantilla($hojas));

        /*
         * Nada quedó a medias. Un instrumento con reactivos y sin claves
         * publica, se asigna y puntúa cero sin que nadie lo note: es peor que
         * no haber importado.
         */
        $this->assertNull(Instrumento::query()->where('clave', 'tamiz_demo')->first());
        $this->assertSame(0, Reactivo::query()->count());
    }

    public function test_detecta_un_likert_sin_opciones(): void
    {
        $hojas = $this->plantillaValida();

        // Se le quitan las opciones a R02, que es likert.
        $hojas['opciones'] = [
            $hojas['opciones'][0],
            $hojas['opciones'][1],
            $hojas['opciones'][2],
        ];

        $reporte = app(ImportadorInstrumento::class)->importar($this->plantilla($hojas));

        $this->assertTrue($reporte->tieneErrores());

        $mensajes = array_column($reporte->errores(), 'mensaje');

        $this->assertNotEmpty(array_filter(
            $mensajes,
            static fn (string $mensaje): bool => str_contains($mensaje, 'R02')
                && str_contains($mensaje, 'no tiene ninguna opción')
        ));
    }

    public function test_detecta_un_tipo_de_reactivo_inexistente(): void
    {
        $hojas = $this->plantillaValida();
        $hojas['reactivos'][1][2] = 'likert_99';

        $reporte = app(ImportadorInstrumento::class)->importar($this->plantilla($hojas));

        $errores = $reporte->porHoja();

        $this->assertArrayHasKey('reactivos', $errores);
        $this->assertSame(2, $errores['reactivos'][0]['fila']);
        $this->assertStringContainsString('likert_99', $errores['reactivos'][0]['mensaje']);
    }

    public function test_reporta_varios_errores_de_una_sola_pasada(): void
    {
        $hojas = $this->plantillaValida();
        $hojas['reactivos'][1][1] = 'BLOQUE_FANTASMA';
        $hojas['claves'][2][2] = 'NO_EXISTE';
        $hojas['baremos'][1][1] = 'poblacion_inventada';

        $reporte = app(ImportadorInstrumento::class)->importar($this->plantilla($hojas));

        /*
         * Todos de una vez. Devolver el primero obligaría a subir la hoja
         * tantas veces como errores tenga, y una plantilla de doscientos
         * reactivos los tiene por docenas.
         */
        $this->assertGreaterThanOrEqual(3, $reporte->errores());
        $this->assertArrayHasKey('reactivos', $reporte->porHoja());
        $this->assertArrayHasKey('baremos', $reporte->porHoja());
    }

    public function test_el_contenido_importado_por_un_tenant_queda_marcado_como_suyo(): void
    {
        $reporte = app(ImportadorInstrumento::class)->importar(
            $this->plantilla($this->plantillaValida()),
            organizacionIdContenido: 42
        );

        $this->assertFalse($reporte->tieneErrores());

        $ambitos = Reactivo::query()->pluck('organizacion_id_contenido')->unique()->all();

        $this->assertSame([42], array_values($ambitos));
    }
}
