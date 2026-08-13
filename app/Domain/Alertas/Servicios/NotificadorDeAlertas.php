<?php

declare(strict_types=1);

namespace App\Domain\Alertas\Servicios;

use App\Domain\Accesos\Modelos\PersonaRolAlcance;
use App\Domain\Alertas\Contratos\NotificaAlertas;
use App\Domain\Alertas\Modelos\Alerta;
use App\Domain\Alertas\Modelos\AlertaDestinatario;
use App\Domain\Personas\Modelos\Persona;
use App\Mail\AlertaCritica;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * La notificación REAL (Doc 06 §5). Sustituye al stub de la Fase 6.
 *
 * Resuelve los destinatarios por rol —no por persona— y manda por los canales
 * configurados. Tres decisiones que importan:
 *
 * 1. **La alerta ya está escrita cuando esto corre.** Notificar y registrar son
 *    actos separados: si un problema de correo pudiera impedir el registro,
 *    desaparecería el rastro de que hubo un riesgo detectado.
 * 2. **El correo NO lleva el contenido de la respuesta.** Dice que hay una
 *    alerta crítica y dónde verla. Un correo con "contestó que sí a la pregunta
 *    de ideación suicida" viaja por servidores que no son de nadie y se queda en
 *    la bandeja de entrada de quien lo reciba, para siempre.
 * 3. **Un canal que falla no tumba a los demás.** Si el correo revienta, la
 *    campana in-app tiene que haber quedado igual; es la que la psicóloga sí va
 *    a ver cuando entre.
 */
class NotificadorDeAlertas implements NotificaAlertas
{
    public function notificar(Alerta $alerta): void
    {
        $destinos = AlertaDestinatario::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $alerta->organizacion_id)
            ->para($alerta->tipo, $alerta->severidad)
            ->get();

        if ($destinos->isEmpty()) {
            /*
             * Sin destinatarios configurados la alerta se queda registrada y
             * NADIE se entera. Se grita en el log porque es una falla de
             * configuración con consecuencias clínicas, no un caso normal —y
             * la compuerta del protocolo de actuación existe justamente para
             * que esto no pase con instrumentos que tienen centinelas.
             */
            Log::warning('Alerta sin destinatarios configurados', [
                'alerta_id' => $alerta->id,
                'organizacion_id' => $alerta->organizacion_id,
                'tipo' => $alerta->tipo,
                'severidad' => $alerta->severidad,
            ]);

            return;
        }

        $porCanal = $destinos->groupBy('canal');

        foreach ($porCanal as $canal => $delCanal) {
            $roles = $delCanal->pluck('rol_id')->unique()->all();
            $personas = $this->personasConRol($roles, $alerta->organizacion_id);

            match ($canal) {
                'correo' => $this->porCorreo($alerta, $personas),

                // La campana in-app no necesita mandar nada: la alerta ya está
                // en la base y el centro de alertas la lee de ahí.
                'app' => null,

                // SMS es de la V2 (Doc 01 §4). Se registra para que quien lo
                // configuró sepa que todavía no sale.
                'sms' => Log::info('Canal SMS pendiente (V2)', ['alerta_id' => $alerta->id]),

                default => null,
            };
        }

        $alerta->update(['estado' => 'notificada']);
    }

    /**
     * Quién tiene ese rol vigente en esa organización.
     *
     * @param  list<int>  $roles
     * @return Collection<int, Persona>
     */
    private function personasConRol(array $roles, int $organizacionId): Collection
    {
        $personaIds = PersonaRolAlcance::query()
            ->withoutGlobalScopes()
            ->where('organizacion_id', $organizacionId)
            ->whereIn('rol_id', $roles)
            ->vigentes()
            ->pluck('persona_id')
            ->unique()
            ->all();

        /** @var Collection<int, Persona> */
        return Persona::query()->whereIn('id', $personaIds)->get();
    }

    /**
     * El correo vive en la CUENTA, no en la persona: una persona sin cuenta no
     * tiene a dónde recibirlo, y eso es correcto —quien atiende alertas
     * críticas entra al sistema—.
     *
     * @param  Collection<int, Persona>  $personas
     */
    private function porCorreo(Alerta $alerta, Collection $personas): void
    {
        $cuentas = User::query()
            ->whereIn('persona_id', $personas->pluck('id')->all())
            ->get()
            ->keyBy('persona_id');

        foreach ($personas as $persona) {
            $cuenta = $cuentas->get($persona->id);

            if ($cuenta === null) {
                continue;
            }

            try {
                /*
                 * Se manda AHORA, no encolado. Una alerta crítica que espera su
                 * turno en la cola detrás de doscientos correos de invitación
                 * llega cuando ya no sirve, y el tiempo de respuesta es
                 * justamente lo que el protocolo de actuación se compromete a
                 * cumplir.
                 */
                Mail::to($cuenta->email)->send(new AlertaCritica($alerta, $persona));
            } catch (Throwable $error) {
                // Un canal que falla no tumba a los demás ni al registro.
                Log::error('No se pudo enviar la alerta por correo', [
                    'alerta_id' => $alerta->id,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }
}
