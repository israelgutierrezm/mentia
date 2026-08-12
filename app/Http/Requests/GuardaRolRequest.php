<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accesos\CatalogoPermisos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardaRolRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:80'],

            // El permiso se valida contra el CATÁLOGO DEL CÓDIGO, no contra la
            // tabla `permissions`: si alguien sembró de más en la base, ese
            // permiso no lo consulta nadie y conceder algo que no protege nada
            // sólo sirve para confundir a quien configura el rol.
            'permisos' => ['present', 'array'],
            'permisos.*' => ['string', Rule::in(CatalogoPermisos::claves())],

            /*
             * 1..4. El tope no es cosmético: es lo que impide que un
             * reclutador vea un PHQ-9 con el mismo permiso con el que ve un
             * test de razonamiento (Doc 06 §3, no discriminación laboral).
             */
            'nivel_sensibilidad_max' => ['required', 'integer', 'between:1,4'],
        ];
    }
}
