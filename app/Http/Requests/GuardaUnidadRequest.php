<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('unidades.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'tipo' => ['required', Rule::in(['plantel', 'sede', 'departamento', 'area'])],
            'estado' => ['sometimes', Rule::in(['activa', 'inactiva'])],

            /*
             * `exists` sin acotar por organización sería una fuga: confirmaría
             * que existe una unidad con ese id en OTRO tenant. El acotamiento
             * real lo hace GestorUnidades resolviendo el padre con el global
             * scope puesto; aquí sólo se comprueba la forma.
             */
            'unidad_padre_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
