<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaAgrupacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('agrupaciones.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'tipo_agrupacion_id' => ['required', 'integer', 'min:1'],
            'unidad_id' => ['nullable', 'integer', 'min:1'],
            'periodo_inicio' => ['nullable', 'date'],
            'periodo_fin' => ['nullable', 'date', 'after_or_equal:periodo_inicio'],
            'estado' => ['sometimes', Rule::in(['activa', 'cerrada'])],
        ];
    }
}
