<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organizaciones\Modelos\Unidad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unidad
 */
class UnidadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'unidad_padre_id' => $this->unidad_padre_id,
            'creado_en' => $this->creado_en?->toIso8601String(),
        ];
    }
}
