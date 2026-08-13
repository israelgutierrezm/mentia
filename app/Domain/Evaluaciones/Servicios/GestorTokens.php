<?php

declare(strict_types=1);

namespace App\Domain\Evaluaciones\Servicios;

use App\Domain\Evaluaciones\Modelos\Asignacion;
use App\Domain\Evaluaciones\Modelos\AsignacionDestinatario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens de un solo uso para las ligas de aplicación.
 *
 * TRES DECISIONES:
 *
 * 1. **Se guarda el HASH, no el token.** Quien lea la base —un respaldo, un
 *    volcado, una consulta de soporte— no debe poder entrar a contestar en
 *    nombre de nadie. El token en claro sólo existe el instante en que se
 *    manda por correo.
 * 2. **De un solo uso.** Al canjearse se marca `token_usado_en`. Una liga
 *    reenviada por WhatsApp llega a más gente de la que se pensó.
 * 3. **Expira con la VENTANA de la asignación, no con un plazo propio.** Un
 *    token que sobreviviera al cierre de su asignación dejaría contestar
 *    fuera de plazo, y los resultados de una campaña con fecha de corte
 *    dejarían de ser comparables.
 */
class GestorTokens
{
    /**
     * Genera un token y devuelve el CLARO. Es la única vez que existe.
     *
     * Quien llama tiene que mandarlo de inmediato: no hay forma de volver a
     * obtenerlo, y eso es a propósito.
     */
    public function generar(AsignacionDestinatario $destinatario): string
    {
        $claro = $this->tokenClaro();

        $destinatario->update([
            'token' => $this->hashear($claro),
            'token_expira_en' => $destinatario->asignacion->ventana_fin,
            'token_usado_en' => null,
        ]);

        return $claro;
    }

    /**
     * Resuelve un token en claro a su destinatario, si sigue sirviendo.
     *
     * Devuelve null en TODOS los casos de fallo —no existe, ya se usó, expiró,
     * la asignación se cerró— sin distinguirlos. Un mensaje que dijera "ese
     * token ya se usó" le confirmaría a quien prueba tokens al azar que
     * acertó uno.
     */
    public function resolver(string $tokenClaro, ?Carbon $al = null): ?AsignacionDestinatario
    {
        if (strlen($tokenClaro) !== 64) {
            return null;
        }

        $destinatario = AsignacionDestinatario::query()
            ->where('token', $this->hashear($tokenClaro))
            ->with('asignacion')
            ->first();

        if ($destinatario === null || ! $destinatario->tokenVigente($al)) {
            return null;
        }

        return $destinatario;
    }

    /**
     * Canjea: marca el token como usado y pasa el destinatario a `en_curso`.
     *
     * Va en una transacción con bloqueo de fila. Sin él, dos peticiones
     * simultáneas con el mismo token —el doble clic de siempre, o dos personas
     * con la misma liga reenviada— pasarían las dos la comprobación de
     * "todavía no se ha usado" antes de que ninguna lo marcara.
     */
    public function canjear(string $tokenClaro, ?Carbon $al = null): ?AsignacionDestinatario
    {
        $momento = $al ?? Carbon::now();

        return DB::transaction(function () use ($tokenClaro, $momento): ?AsignacionDestinatario {
            $destinatario = AsignacionDestinatario::query()
                ->where('token', $this->hashear($tokenClaro))
                ->lockForUpdate()
                ->with('asignacion')
                ->first();

            if ($destinatario === null || ! $destinatario->tokenVigente($momento)) {
                return null;
            }

            $destinatario->update([
                'token_usado_en' => $momento,
                'estado' => 'en_curso',
            ]);

            return $destinatario;
        });
    }

    /**
     * Invalida todos los tokens de una asignación. Se llama al cerrarla o
     * cancelarla.
     */
    public function invalidarDe(Asignacion $asignacion): int
    {
        return AsignacionDestinatario::query()
            ->where('asignacion_id', $asignacion->id)
            ->whereNull('token_usado_en')
            ->update(['token_expira_en' => Carbon::now()->subSecond()]);
    }

    /**
     * 64 caracteres hex de 32 bytes aleatorios criptográficamente seguros.
     *
     * `Str::random()` no sirve aquí: usa el generador del framework, y estos
     * tokens son la única credencial de quien contesta por liga anónima.
     */
    private function tokenClaro(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * SHA-256 y no bcrypt: el token ya es 32 bytes aleatorios, así que no hay
     * nada que reforzar contra fuerza bruta, y bcrypt obligaría a recorrer
     * toda la tabla comparando uno por uno en vez de buscar por índice.
     */
    private function hashear(string $tokenClaro): string
    {
        return hash('sha256', $tokenClaro);
    }
}
