<?php

declare(strict_types=1);

namespace App\Domain\Personas\Servicios;

use App\Domain\Personas\Excepciones\TutoriaInvalida;
use App\Domain\Personas\Modelos\OrganizacionPersona;
use App\Domain\Personas\Modelos\Persona;
use App\Domain\Personas\Modelos\Tutoria;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Quién puede actuar en nombre de un menor, y quién lo acredita.
 *
 * Esta es la compuerta más delicada del módulo de personas: una tutoría
 * vigente abre el expediente psicológico de un menor a otra persona. Por eso
 * el registro NO da acceso —nace `pendiente_validacion`— y la validación es un
 * acto separado, de alguien con `tutorias.validar`, que queda con nombre en la
 * fila.
 *
 * `tutorias` es una tabla GLOBAL: la misma madre acredita tutela en la escuela
 * y en el consultorio. El acotamiento por tenant se hace sobre el MENOR: sólo
 * se administran tutorías de personas vinculadas a la organización activa.
 */
class GestorTutorias
{
    public function __construct(private readonly ContextoOrganizacion $contexto) {}

    /**
     * Registra la tutoría. NO da acceso: nace pendiente de validación.
     *
     * @throws TutoriaInvalida
     */
    public function registrar(
        Persona $tutor,
        Persona $menor,
        string $parentesco,
        ?string $vigenciaInicio = null,
        ?string $vigenciaFin = null,
    ): Tutoria {
        if ($tutor->id === $menor->id) {
            throw TutoriaInvalida::porSerLaMismaPersona();
        }

        $this->exigirMenorDelTenant($menor);

        /*
         * El único de (tutor, menor) impide duplicados. Si ya existe una
         * revocada, se REGISTRA de nuevo sobre la misma fila volviéndola a
         * pendiente: crear otra chocaría contra el único, y dejarla revocada
         * obligaría a borrar historia para poder re-acreditar a la misma madre.
         */
        $tutoria = Tutoria::query()->firstOrNew([
            'tutor_persona_id' => $tutor->id,
            'menor_persona_id' => $menor->id,
        ]);

        $tutoria->fill([
            'parentesco' => $parentesco,
            'estado' => 'pendiente_validacion',
            'vigencia_inicio' => $vigenciaInicio ?? Carbon::now()->toDateString(),
            'vigencia_fin' => $vigenciaFin,
            'validada_por' => null,
        ])->save();

        return $tutoria;
    }

    /**
     * Acredita la tutoría. Es lo que la vuelve vigente y abre el acceso.
     *
     * @throws TutoriaInvalida
     */
    public function validar(Tutoria $tutoria, Persona $validador): Tutoria
    {
        $this->exigirMenorDelTenant($tutoria->menor);

        if ($tutoria->estado !== 'pendiente_validacion') {
            throw TutoriaInvalida::porNoEstarPendiente($tutoria->estado);
        }

        /*
         * Nadie valida su propia tutoría.
         *
         * Sin esta regla, el flujo de autorregistro sería una puerta abierta:
         * cualquiera declara ser la madre de un menor, se valida solo y se
         * lleva su expediente. La validación tiene que ser un acto de alguien
         * más, y por eso queda con nombre en `validada_por`.
         */
        if ($validador->id === $tutoria->tutor_persona_id) {
            throw TutoriaInvalida::porAutoValidacion();
        }

        $tutoria->update([
            'estado' => 'vigente',
            'validada_por' => $validador->id,
        ]);

        return $tutoria;
    }

    /**
     * Corta el acceso. La fila se conserva: el rastro de que esa tutela existió
     * es parte de la historia del expediente.
     */
    public function revocar(Tutoria $tutoria): Tutoria
    {
        $this->exigirMenorDelTenant($tutoria->menor);

        $tutoria->update([
            'estado' => 'revocada',
            'vigencia_fin' => Carbon::now()->toDateString(),
        ]);

        return $tutoria;
    }

    /**
     * Las tutorías de menores vinculados a la organización activa.
     *
     * @return Collection<int, Tutoria>
     */
    public function listar(): Collection
    {
        $organizacionId = $this->organizacionActiva();

        /** @var Collection<int, Tutoria> */
        return Tutoria::query()
            ->with(['tutor', 'menor'])
            ->whereIn('menor_persona_id', $this->personasDelTenant($organizacionId))
            ->orderByRaw("FIELD(estado, 'pendiente_validacion', 'vigente', 'revocada', 'extinta_mayoria_edad')")
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<OrganizacionPersona>
     */
    private function personasDelTenant(int $organizacionId): \Illuminate\Database\Eloquent\Builder
    {
        return OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->select('persona_id')
            ->where('organizacion_id', $organizacionId)
            ->where('estado', 'activa');
    }

    /**
     * @throws TutoriaInvalida
     */
    private function exigirMenorDelTenant(Persona $menor): void
    {
        $existe = OrganizacionPersona::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $this->organizacionActiva())
            ->where('persona_id', $menor->id)
            ->where('estado', 'activa')
            ->exists();

        if (! $existe) {
            throw TutoriaInvalida::porMenorFueraDelTenant();
        }
    }

    private function organizacionActiva(): int
    {
        $id = $this->contexto->id();

        if ($id === null) {
            throw new RuntimeException('No hay organización activa.');
        }

        return $id;
    }
}
