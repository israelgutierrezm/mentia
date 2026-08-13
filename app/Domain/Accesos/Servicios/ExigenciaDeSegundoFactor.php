<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Accesos\Modelos\RolSensibilidadMax;
use App\Domain\Personas\Modelos\Persona;
use App\Models\User;

/**
 * Quién está OBLIGADO a tener segundo factor (Doc 06 §4).
 *
 * No es una preferencia: quien puede abrir el expediente clínico de un menor
 * —sensibilidad 3 o 4— entra con dos factores. Y como es un requisito del ROL y
 * no de la persona, alguien a quien hoy le suben el rol queda obligado mañana
 * sin que nadie tenga que acordarse de avisarle.
 *
 * El umbral es configurable porque una organización puede querer exigirlo a
 * todos; lo que no se puede es bajarlo por debajo de 3.
 */
class ExigenciaDeSegundoFactor
{
    public function obligatorioPara(Persona $persona): bool
    {
        $nivelMinimo = max(3, (int) config('mentia.seguridad.nivel_minimo_2fa', 3));

        $roles = PersonaRolAlcance::query()
            ->withoutGlobalScopes()
            ->where('persona_id', $persona->id)
            ->vigentes()
            ->pluck('rol_id')
            ->unique()
            ->all();

        if ($roles === []) {
            return false;
        }

        return RolSensibilidadMax::query()
            ->whereIn('rol_id', $roles)
            ->where('nivel_sensibilidad_max', '>=', $nivelMinimo)
            ->exists();
    }

    /**
     * ¿Le falta activarlo?
     *
     * Es lo que el middleware pregunta: obligado Y sin confirmar. Preguntar
     * sólo por "obligado" mandaría a la pantalla de activación también a quien
     * ya lo tiene puesto.
     */
    public function pendientePara(User $usuario): bool
    {
        if ($usuario->dos_factores_confirmado_en !== null) {
            return false;
        }

        return $this->obligatorioPara($usuario->persona);
    }
}
