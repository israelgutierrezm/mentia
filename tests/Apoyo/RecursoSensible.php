<?php

declare(strict_types=1);

namespace Tests\Apoyo;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use Illuminate\Database\Eloquent\Model;

/**
 * Recurso de prueba con nivel de sensibilidad declarado.
 *
 * Los modelos que implementan TieneSensibilidad de verdad —resultados,
 * documentos, notas profesionales— nacen en las fases 2 y 7. Éste permite
 * probar la dimensión 3 desde ahora, que es cuando se escribió.
 */
class RecursoSensible extends Model implements TieneSensibilidad
{
    protected $table = 'recursos_de_prueba';

    public function __construct(private readonly int $nivel = 1)
    {
        parent::__construct();
    }

    public static function deNivel(int $nivel): self
    {
        return new self($nivel);
    }

    public function nivelSensibilidad(): int
    {
        return $this->nivel;
    }
}
