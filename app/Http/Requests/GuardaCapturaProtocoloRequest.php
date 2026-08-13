<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardaCapturaProtocoloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('protocolos.capturar') ?? false;
    }

    /**
     * Las mismas reglas que el endpoint de API §5: web y API llaman al mismo
     * servicio y no pueden aceptar cosas distintas.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'persona_uuid' => ['required', 'uuid'],
            'version_instrumento_id' => ['required', 'integer'],

            /*
             * `before_or_equal:today` y no una fecha libre: un protocolo con
             * fecha futura normalizaría con una edad que la persona todavía no
             * tiene, y en preescolar seis meses cambian la tabla.
             */
            'fecha_aplicacion' => ['required', 'date', 'before_or_equal:today'],

            'escalas' => ['required', 'array', 'min:1'],
            'escalas.*.clave' => ['required', 'string', 'max:40'],
            'escalas.*.puntaje_bruto' => ['required', 'numeric'],
            'escalas.*.puntaje_escalar' => ['nullable', 'numeric'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
