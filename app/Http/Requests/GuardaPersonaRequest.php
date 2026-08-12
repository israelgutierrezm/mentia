<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Soporte\Reglas\Curp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaPersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('personas.crear') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:120'],
            'primer_apellido' => ['required', 'string', 'max:80'],
            'segundo_apellido' => ['nullable', 'string', 'max:80'],

            /*
             * La fecha es OBLIGATORIA aunque la CURP la contenga: es el insumo
             * de todos los baremos por edad y la mitad de la verificación de
             * identidad. Derivarla de la CURP dejaría sin fecha a los
             * extranjeros y a los casos sin documento, que sí existen.
             */
            'fecha_nacimiento' => ['required', 'date', 'before:today'],

            'sexo_registral' => ['required', Rule::in(['M', 'F', 'X'])],

            /*
             * Nullable: hay extranjeros, menores sin trámite y capturas sin
             * documento.
             *
             * SIN `unique`, a propósito. Que la CURP ya exista no es un error:
             * es el caso normal del expediente de vida —la persona que fue
             * evaluada en la escuela llega años después a una empresa—. Quien
             * decide si se crea, se vincula o se detiene es RegistroPersonas,
             * comparando la fecha de nacimiento. Con `unique` aquí, vincular
             * sería imposible y cada tenant terminaría creando su propio
             * duplicado de la misma persona.
             */
            'curp' => ['nullable', 'string', 'size:18', new Curp],

            'matricula_o_num_empleado' => ['nullable', 'string', 'max:60'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('curp'))) {
            $this->merge(['curp' => strtoupper(trim($this->string('curp')->toString()))]);
        }
    }
}
