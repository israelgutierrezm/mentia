<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Personas\Modelos\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datos de identificación de una persona.
 *
 * NO expone el `id`: hacia afuera viaja el `uuid`. Un id se cuenta, y quien
 * pidiera 1, 2, 3… se llevaría el padrón entero de la plataforma —que es
 * global, no del tenant—.
 *
 * Tampoco expone la CURP completa por omisión: es un identificador oficial y
 * un listado de personas no necesita enseñarla. Se ve enmascarada, y quien
 * necesita la completa la pide en la ficha, que pasa por AccesoService y deja
 * bitácora.
 *
 * @mixin Persona
 */
class PersonaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'nombres' => $this->nombres,
            'primer_apellido' => $this->primer_apellido,
            'segundo_apellido' => $this->segundo_apellido,
            'nombre_completo' => $this->nombreCompleto(),
            'fecha_nacimiento' => $this->fecha_nacimiento->toDateString(),
            'sexo_registral' => $this->sexo_registral,
            'verificacion_identidad' => $this->verificacion_identidad,
            'curp_enmascarada' => $this->curpEnmascarada(),
        ];
    }

    private function curpEnmascarada(): ?string
    {
        if ($this->curp === null) {
            return null;
        }

        // Primeros 4 y últimos 2: suficiente para que un capturista reconozca
        // el registro que acaba de dar de alta, insuficiente para reconstruirla.
        return substr($this->curp, 0, 4).'••••••••••••'.substr($this->curp, -2);
    }
}
