<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaAlcanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'persona_uuid' => ['required', 'uuid', Rule::exists('personas', 'uuid')],
            'rol_id' => ['required', 'integer', 'min:1'],
            'alcance_tipo' => ['required', Rule::in([
                PersonaRolAlcance::TIPO_ORGANIZACION,
                PersonaRolAlcance::TIPO_UNIDAD,
                PersonaRolAlcance::TIPO_AGRUPACION,
                PersonaRolAlcance::TIPO_PERSONA,
            ])],

            // Obligatorio salvo para alcance de organización completa, que se
            // resuelve solo a la organización activa.
            'alcance_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(
                    fn (): bool => $this->input('alcance_tipo') !== PersonaRolAlcance::TIPO_ORGANIZACION
                ),
            ],

            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
        ];
    }
}
