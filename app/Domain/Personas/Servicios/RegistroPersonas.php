<?php

declare(strict_types=1);

namespace App\Domain\Personas\Servicios;

use App\Domain\Organizaciones\Modelos\Organizacion;
use App\Domain\Personas\Datos\DatosPersona;
use App\Domain\Personas\Excepciones\IdentidadEnConflicto;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alta de personas con verificación de identidad CURP + fecha de nacimiento
 * (Doc 03 §M2).
 *
 * El punto de este servicio es NO DUPLICAR PERSONAS. La persona es global y
 * permanente: si la niña que tamizaron en primaria llega a los 22 años a una
 * empresa tenant, tiene que ser la misma fila —si no, el expediente de vida
 * queda partido en dos y la comparación longitudinal, que es el producto, deja
 * de existir—.
 *
 * De ahí las tres salidas posibles:
 *  - CURP nueva o sin CURP  → se crea la persona     (origen `creada`)
 *  - CURP existente y coincide la fecha → se vincula (origen `vinculada`)
 *  - CURP existente y NO coincide la fecha → se detiene el alta
 */
class RegistroPersonas
{
    /**
     * Da de alta o vincula, según lo que ya exista en la plataforma.
     *
     * @throws IdentidadEnConflicto
     */
    public function altaEnOrganizacion(
        DatosPersona $datos,
        Organizacion $organizacion,
    ): OrganizacionPersona {
        return DB::transaction(function () use ($datos, $organizacion): OrganizacionPersona {
            $existente = $this->buscarPorCurp($datos->curp);

            if ($existente !== null) {
                $this->exigirMismaFechaDeNacimiento($existente, $datos);

                return $this->vincular($existente, $organizacion, 'vinculada', $datos->matricula);
            }

            $persona = Persona::query()->create([
                'curp' => $datos->curp,
                'nombres' => $datos->nombres,
                'primer_apellido' => $datos->primerApellido,
                'segundo_apellido' => $datos->segundoApellido,
                'fecha_nacimiento' => $datos->fechaNacimiento->toDateString(),
                'sexo_registral' => $datos->sexoRegistral,
                'verificacion_identidad' => 'no_verificada',
            ]);

            return $this->vincular($persona, $organizacion, 'creada', $datos->matricula);
        });
    }

    /**
     * Liga a la organización una persona que ya existe.
     *
     * Reactiva el vínculo si la persona ya estuvo aquí y se dio de baja: el
     * único de (organizacion_id, persona_id) impide crear un segundo, y crear
     * un segundo sería además perder la fecha del alta original.
     */
    public function vincular(
        Persona $persona,
        Organizacion $organizacion,
        string $origen = 'vinculada',
        ?string $matricula = null,
    ): OrganizacionPersona {
        $vinculo = OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacion->id)
            ->where('persona_id', $persona->id)
            ->first();

        if ($vinculo !== null) {
            $vinculo->update([
                'estado' => 'activa',
                'fecha_baja' => null,
                'matricula_o_num_empleado' => $matricula ?? $vinculo->matricula_o_num_empleado,
            ]);

            return $vinculo;
        }

        return OrganizacionPersona::query()->create([
            'organizacion_id' => $organizacion->id,
            'persona_id' => $persona->id,
            'matricula_o_num_empleado' => $matricula,
            'estado' => 'activa',
            'origen_alta' => $origen,
            'fecha_alta' => Carbon::now()->toDateString(),
        ]);
    }

    public function darDeBaja(OrganizacionPersona $vinculo): OrganizacionPersona
    {
        /*
         * Baja LÓGICA. El vínculo no se borra porque los resultados que esa
         * persona generó aquí siguen existiendo y siguen perteneciendo al
         * contexto de este tenant; borrar la fila dejaría datos huérfanos que
         * ninguna pantalla sabría a quién atribuir.
         */
        $vinculo->update([
            'estado' => 'baja',
            'fecha_baja' => Carbon::now()->toDateString(),
        ]);

        return $vinculo;
    }

    /**
     * La búsqueda es GLOBAL, sin scope de tenant: el objetivo es encontrar a
     * la persona aunque su expediente se haya creado en otra organización.
     * Esto NO le da acceso a esa organización a nada de lo que la persona
     * generó en otra parte — eso lo gobiernan los consentimientos (M4).
     */
    private function buscarPorCurp(?string $curp): ?Persona
    {
        if ($curp === null) {
            return null;
        }

        return Persona::query()->where('curp', $curp)->first();
    }

    /**
     * @throws IdentidadEnConflicto
     */
    private function exigirMismaFechaDeNacimiento(Persona $persona, DatosPersona $datos): void
    {
        if ($persona->fecha_nacimiento->isSameDay($datos->fechaNacimiento)) {
            return;
        }

        throw IdentidadEnConflicto::porFechaDistinta((string) $persona->curp);
    }
}
