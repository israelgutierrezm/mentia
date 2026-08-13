<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Datos;

/**
 * El resultado de importar una plantilla, fila a fila.
 *
 * El Doc 04 pide "reporte de errores fila a fila" y esa exigencia tiene una
 * razón operativa: quien captura un instrumento con doscientos reactivos en
 * Excel necesita saber que la fila 87 de la hoja `claves` cita una escala que
 * no existe. Un "hubo un error al importar" lo obliga a revisar doscientas
 * filas a mano.
 */
class ReporteImportacion
{
    /** @var list<array{hoja: string, fila: int, columna: string|null, mensaje: string}> */
    private array $errores = [];

    /** @var array<string, int> */
    private array $creados = [];

    public function error(string $hoja, int $fila, string $mensaje, ?string $columna = null): void
    {
        $this->errores[] = [
            'hoja' => $hoja,
            'fila' => $fila,
            'columna' => $columna,
            'mensaje' => $mensaje,
        ];
    }

    public function contar(string $que, int $cuantos = 1): void
    {
        $this->creados[$que] = ($this->creados[$que] ?? 0) + $cuantos;
    }

    public function tieneErrores(): bool
    {
        return $this->errores !== [];
    }

    /**
     * @return list<array{hoja: string, fila: int, columna: string|null, mensaje: string}>
     */
    public function errores(): array
    {
        return $this->errores;
    }

    /**
     * @return array<string, int>
     */
    public function creados(): array
    {
        return $this->creados;
    }

    /**
     * Los errores agrupados por hoja, que es como se corrigen: abriendo una
     * pestaña del Excel a la vez.
     *
     * @return array<string, list<array{fila: int, columna: string|null, mensaje: string}>>
     */
    public function porHoja(): array
    {
        $agrupados = [];

        foreach ($this->errores as $error) {
            $agrupados[$error['hoja']][] = [
                'fila' => $error['fila'],
                'columna' => $error['columna'],
                'mensaje' => $error['mensaje'],
            ];
        }

        return $agrupados;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraRespuesta(): array
    {
        return [
            'exito' => ! $this->tieneErrores(),
            'creados' => $this->creados,
            'errores' => $this->errores,
            'errores_por_hoja' => $this->porHoja(),
            'total_errores' => count($this->errores),
        ];
    }
}
