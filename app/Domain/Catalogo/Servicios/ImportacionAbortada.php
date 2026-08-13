<?php

declare(strict_types=1);

namespace App\Domain\Catalogo\Servicios;

use RuntimeException;

/**
 * Señal interna para deshacer la transacción cuando el reporte trae errores.
 *
 * No sale del importador: quien llama recibe el ReporteImportacion, que es lo
 * que sirve para corregir la hoja. Una excepción no le diría a nadie qué fila
 * arreglar.
 */
class ImportacionAbortada extends RuntimeException {}
