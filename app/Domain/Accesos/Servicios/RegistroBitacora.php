<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\Datos\DecisionAcceso;
use App\Domain\Accesos\Modelos\Bitacora;
use App\Domain\Personas\Modelos\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Escribe en la bitácora. Nunca lee para decidir nada.
 *
 * Aparte de AccesoService para que el servicio de autorización no cargue con
 * la petición HTTP: la IP y el user agent son del transporte, y AccesoService
 * también se llama desde jobs de cola, donde no hay ninguna de las dos.
 */
class RegistroBitacora
{
    public function __construct(private readonly Request $peticion) {}

    public function registrar(
        Persona $actor,
        string $accion,
        Persona $sujeto,
        ?Model $recurso,
        ?int $propositoId,
        DecisionAcceso $decision,
        ?int $organizacionId,
    ): Bitacora {
        return Bitacora::query()->create([
            'organizacion_id' => $organizacionId,
            'actor_persona_id' => $actor->id,
            'accion' => $accion,
            'recurso_tipo' => $this->tipoDe($recurso),
            'recurso_id' => $recurso?->getKey(),
            'persona_afectada_id' => $sujeto->id,
            'proposito_id' => $propositoId,
            'resultado' => $decision->permitido ? 'permitido' : 'denegado',
            'motivo' => Str::limit($decision->motivo, 159, ''),
            'ip' => $this->peticion->ip(),
            'user_agent' => Str::limit((string) $this->peticion->userAgent(), 254, ''),

            /*
             * Con milésimas. Un lote de respuestas produce varias decisiones en
             * el mismo segundo, y sin la precisión el orden se pierde justo
             * cuando hace falta reconstruir qué pasó.
             */
            'registrado_en' => Carbon::now(),
        ]);
    }

    /**
     * El tipo del recurso, en corto y estable.
     *
     * Se guarda el nombre de clase sin namespace: `Aplicacion`, no
     * `App\Domain\Evaluaciones\Modelos\Aplicacion`. La bitácora se conserva
     * años y sobrevive a los refactors; un namespace guardado en 2026 deja de
     * significar nada cuando la clase se mueve de carpeta, y la columna admite
     * 80 caracteres.
     */
    private function tipoDe(?Model $recurso): string
    {
        if ($recurso === null) {
            return 'Persona';
        }

        return class_basename($recurso);
    }
}
