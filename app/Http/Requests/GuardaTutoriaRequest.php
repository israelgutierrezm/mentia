<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaTutoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tutorias.validar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tutor_uuid' => ['required', 'uuid', 'different:menor_uuid'],
            'menor_uuid' => ['required', 'uuid'],
            'parentesco' => ['required', Rule::in(['madre', 'padre', 'tutor_legal', 'otro'])],
            'vigencia_inicio' => ['nullable', 'date'],
            'vigencia_fin' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tutor_uuid.different' => 'Una persona no puede ser tutora de sí misma.',
        ];
    }
}
