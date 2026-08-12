<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organizaciones\Modelos\Agrupacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agrupacion
 */
class AgrupacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'estado' => $this->estado,
            'unidad_id' => $this->unidad_id,
            'tipo_agrupacion_id' => $this->tipo_agrupacion_id,
            'periodo_inicio' => $this->periodo_inicio?->toDateString(),
            'periodo_fin' => $this->periodo_fin?->toDateString(),
            'miembros_vigentes' => $this->whenCounted('miembrosVigentes'),
        ];
    }
}
