<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaAsignacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        if ($usuario === null) {
            return false;
        }

        /*
         * Una asignación DISCRETA exige su propio permiso: es el uso clínico,
         * y quien puede lanzar tamizajes de grupo no necesariamente puede
         * asignar de forma que nadie más lo vea.
         */
        if ($this->boolean('es_discreta')) {
            return $usuario->can('evaluaciones.asignar_individual_discreta');
        }

        return $usuario->can('evaluaciones.asignar');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'proposito_id' => ['required', 'integer', 'min:1'],

            'origen_tipo' => ['required', Rule::in(['individual', 'agrupacion', 'campania'])],

            'agrupacion_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn (): bool => $this->input('origen_tipo') === 'agrupacion'),
            ],

            // Sólo para origen individual; la agrupación se expande sola.
            'destinatarios' => ['nullable', 'array'],
            'destinatarios.*' => ['uuid'],

            'version_instrumento_id' => ['nullable', 'integer', 'min:1'],
            'bateria_id' => ['nullable', 'integer', 'min:1'],

            'ventana_inicio' => ['nullable', 'date'],
            'ventana_fin' => ['nullable', 'date', 'after:ventana_inicio'],

            'incluir_nuevos_miembros' => ['sometimes', 'boolean'],
            'es_discreta' => ['sometimes', 'boolean'],

            /*
             * El anonimato es IRREVERSIBLE por diseño (Doc 03 §M6). No se
             * valida nada especial aquí, pero conviene recordarlo: una vez
             * lanzada, no hay forma de recuperar el vínculo persona-respuesta,
             * y eso es lo que la hace creíble.
             */
            'es_anonima' => ['sometimes', 'boolean'],

            'intentos_permitidos' => ['sometimes', 'integer', 'between:1,10'],
            'modo_presentacion' => ['sometimes', Rule::in([
                'infantil', 'adolescente', 'adulto', 'informante', 'examinador', 'kiosco',
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ventana_fin.after' => 'La ventana termina antes de empezar: nadie podría contestar.',
            'agrupacion_id.required' => 'Una asignación de agrupación necesita saber a qué grupo.',
        ];
    }
}
