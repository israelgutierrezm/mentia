<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Domain\Accesos\Contratos\TieneSensibilidad;
use App\Domain\Accesos\Datos\DecisionAcceso;
use App\Domain\Accesos\Datos\Dimension;
use App\Domain\Accesos\Modelos\Rol;
use App\Domain\Consentimientos\Contratos\VerificaConsentimiento;
use App\Domain\Consentimientos\Datos\EstadoConsentimiento;
use App\Domain\Expedientes\Modelos\Expediente;
use App\Domain\Personas\Modelos\Persona;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Database\Eloquent\Model;

/**
 * EL ÚNICO PUNTO DE AUTORIZACIÓN de recursos de personas (Doc 02 §2, regla 2).
 *
 * Resuelve las cuatro dimensiones del Doc 06 §1 en CORTOCIRCUITO —al primer
 * fallo se detiene— y deja bitácora de toda decisión, autorice o niegue.
 *
 * Que sea uno solo es el punto. Estas cuatro comprobaciones repartidas por los
 * controllers producen, inevitablemente, un endpoint al que se le olvidó una:
 * y "se me olvidó el consentimiento" en este sistema significa entregar el
 * expediente psicológico de un menor a quien no debía verlo.
 *
 * Las denegadas se registran igual que las permitidas. Un intento repetido
 * contra el expediente de alguien fuera de alcance es justo lo que un auditor
 * busca; si sólo se guardaran los accesos concedidos, no quedaría rastro.
 */
class AccesoService
{
    public function __construct(
        private readonly ResolutorAlcance $alcances,
        private readonly VerificaConsentimiento $consentimientos,
        private readonly RegistroBitacora $bitacora,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * @param  Persona  $actor  Quién solicita.
     * @param  string  $accion  Permiso Spatie: 'resultados.ver_detalle'.
     * @param  Persona  $sujeto  Sobre quién.
     * @param  Model|null  $recurso  Qué (resultado, expediente, documento…).
     * @param  int|null  $propositoId  Para qué (plantilla de propósito, M6).
     */
    public function autorizar(
        Persona $actor,
        string $accion,
        Persona $sujeto,
        ?Model $recurso = null,
        ?int $propositoId = null,
    ): DecisionAcceso {
        $decision = $this->resolver($actor, $accion, $sujeto, $recurso, $propositoId);

        $this->bitacora->registrar(
            actor: $actor,
            accion: $accion,
            sujeto: $sujeto,
            recurso: $recurso,
            propositoId: $propositoId,
            decision: $decision,
            organizacionId: $this->contexto->id(),
        );

        return $decision;
    }

    private function resolver(
        Persona $actor,
        string $accion,
        Persona $sujeto,
        ?Model $recurso,
        ?int $propositoId,
    ): DecisionAcceso {
        $organizacionId = $this->contexto->id();

        if ($organizacionId === null) {
            return DecisionAcceso::negar(
                Dimension::Permiso,
                'No hay organización activa en el contexto.'
            );
        }

        // ── 1. Permiso ────────────────────────────────────────────────────
        // Spatie resuelve contra el tenant activo porque ContextoOrganizacion
        // fijó el team id al mismo tiempo que la organización.
        if (! $this->tienePermiso($actor, $accion, $sujeto)) {
            return DecisionAcceso::negar(Dimension::Permiso);
        }

        // ── 2. Alcance ────────────────────────────────────────────────────
        if (! $this->alcances->alcanza($actor, $sujeto, $organizacionId)) {
            return DecisionAcceso::negar(Dimension::Alcance);
        }

        // ── 3. Sensibilidad ───────────────────────────────────────────────
        $nivelRecurso = $this->nivelDe($recurso);
        $nivelActor = $this->nivelMaximoDe($actor, $accion, $sujeto, $organizacionId);

        if ($nivelRecurso > $nivelActor) {
            return DecisionAcceso::negar(
                Dimension::Sensibilidad,
                sprintf(
                    'El recurso es de sensibilidad %d y el rol activo alcanza %d.',
                    $nivelRecurso,
                    $nivelActor
                )
            );
        }

        // ── 4. Consentimiento ─────────────────────────────────────────────

        /*
         * El titular y el tutor acreditado NO pasan por esta compuerta.
         *
         * El consentimiento protege a la persona de TERCEROS; exigírselo a
         * ella misma sería pedirle que se autorice ante sí misma. Y el tutor es
         * justamente QUIEN OTORGA el consentimiento en nombre del menor:
         * pedirle el suyo para poder ver sería circular —no podría leer aquello
         * sobre lo que tiene que decidir—.
         *
         * Con esta salida, la compuerta hace lo que la LFPDPPP quiere que haga
         * y no lo contrario.
         */
        if ($this->esTitularOTutor($actor, $sujeto)) {
            return DecisionAcceso::permitir();
        }

        /*
         * Expediente bloqueado por re-consentimiento pendiente: TERCEROS no
         * entran (Doc 06 §3). El titular ya salió arriba — el bloqueo existe
         * para protegerlo a él, no para dejarlo fuera de su propio dato.
         */
        if ($this->expedienteBloqueado($sujeto)) {
            return DecisionAcceso::negar(
                Dimension::Consentimiento,
                'El expediente está bloqueado hasta que la persona re-consienta '
                .'tras cumplir la mayoría de edad.'
            );
        }

        $consentimiento = $this->consentimientos->estadoPara(
            $sujeto, $accion, $propositoId, $organizacionId
        );

        if (! $consentimiento->permiteContinuar()) {
            return DecisionAcceso::negar(Dimension::Consentimiento);
        }

        if ($consentimiento === EstadoConsentimiento::Pendiente) {
            /*
             * Motivo propio, no "Autorizado". Es lo que permite responderle a
             * una auditoría qué accesos se concedieron mientras la
             * verificación de consentimiento era provisional (Fase 1).
             */
            return DecisionAcceso::permitir(
                'Autorizado; consentimiento pendiente de verificación (implementación provisional).'
            );
        }

        return DecisionAcceso::permitir();
    }

    /**
     * Dimensión 1, con la excepción del titular y el tutor.
     *
     * El titular no necesita un permiso para llegar a su propio expediente, ni
     * el tutor vigente al de su tutelado: la mayoría de las personas del
     * sistema no tienen ningún rol asignado, y exigirles uno cerraría el portal
     * de autollenado a todo el mundo.
     */
    private function tienePermiso(Persona $actor, string $accion, Persona $sujeto): bool
    {
        if ($actor->id === $sujeto->id) {
            return true;
        }

        if ($this->alcances->esTutorVigenteDe($actor, $sujeto)) {
            return true;
        }

        return $actor->hasPermissionTo($accion, 'web');
    }

    private function esTitularOTutor(Persona $actor, Persona $sujeto): bool
    {
        return $actor->id === $sujeto->id
            || $this->alcances->esTutorVigenteDe($actor, $sujeto);
    }

    private function expedienteBloqueado(Persona $sujeto): bool
    {
        return Expediente::query()
            ->where('persona_id', $sujeto->id)
            ->where('estado', 'bloqueado')
            ->exists();
    }

    /**
     * Lo que un recurso declara valer. Sin declaración, 1 (general).
     */
    private function nivelDe(?Model $recurso): int
    {
        if ($recurso instanceof TieneSensibilidad) {
            return $recurso->nivelSensibilidad();
        }

        return 1;
    }

    /**
     * El tope de sensibilidad del MEJOR rol aplicable del actor.
     *
     * "Mejor" = el más alto entre los roles que (a) tiene en este tenant y
     * (b) traen realmente el permiso solicitado. Filtrar por el permiso
     * importa: una psicóloga que además es docente no debe ver un resultado
     * clínico ejerciendo el rol docente, pero tampoco perder su nivel 4 por
     * tener el otro rol encima.
     *
     * Titular y tutor no pasan por aquí con tope de rol: sobre su propio
     * expediente alcanzan cualquier nivel, que es lo que la LFPDPPP les
     * reconoce como titulares del dato.
     */
    private function nivelMaximoDe(
        Persona $actor,
        string $accion,
        Persona $sujeto,
        int $organizacionId,
    ): int {
        if ($actor->id === $sujeto->id || $this->alcances->esTutorVigenteDe($actor, $sujeto)) {
            return 4;
        }

        /*
         * `roles.organizacion_id` CALIFICADO. La columna existe en la tabla
         * `roles` y también en el pivote `model_has_roles`, así que sin
         * calificar MySQL responde "Column 'organizacion_id' in where clause
         * is ambiguous" y revienta la autorización entera.
         */
        $roles = $actor->roles()
            ->where('roles.organizacion_id', $organizacionId)
            ->with('topeSensibilidad')
            ->get();

        $nivel = 1;

        foreach ($roles as $rol) {
            // El modelo de rol es configurable (config/permission.php); si
            // alguien lo cambia por uno que no es el nuestro, no tiene tope y
            // se queda en el nivel general.
            if (! $rol instanceof Rol) {
                continue;
            }

            if (! $rol->hasPermissionTo($accion, 'web')) {
                continue;
            }

            $nivel = max($nivel, $rol->nivelSensibilidadMaximo());
        }

        return $nivel;
    }
}
