<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Servicios;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Alta y verificación del segundo factor (TOTP).
 *
 * El secreto y los códigos de recuperación van CIFRADOS por la aplicación,
 * igual que las notas profesionales: quien lea un respaldo de la base no debe
 * poder generar los códigos de nadie.
 *
 * Los códigos de recuperación existen porque, en un sistema donde el acceso lo
 * da la organización, perder el teléfono sin ellos significa una llamada a
 * soporte para que alguien desactive la 2FA por fuera — que es exactamente el
 * agujero que la 2FA venía a tapar.
 */
class SegundoFactor
{
    private const CODIGOS_RECUPERACION = 8;

    public function __construct(private readonly Google2FA $totp) {}

    /**
     * Genera un secreto NUEVO y lo guarda sin confirmar.
     *
     * Sin confirmar a propósito: entre generar el secreto y verificar que la
     * persona lo capturó bien en su aplicación puede pasar cualquier cosa, y
     * darlo por bueno antes dejaría a alguien fuera de su cuenta con un
     * requisito que no puede cumplir.
     */
    public function preparar(User $usuario): string
    {
        $secreto = $this->totp->generateSecretKey();

        $usuario->update([
            'dos_factores_secreto' => Crypt::encryptString($secreto),
            'dos_factores_confirmado_en' => null,
            'dos_factores_recuperacion' => null,
        ]);

        return $secreto;
    }

    /**
     * Confirma el alta con un código de la aplicación del usuario.
     *
     * @return list<string> Los códigos de recuperación, la única vez que existen en claro.
     */
    public function confirmar(User $usuario, string $codigo): array
    {
        if (! $this->verificaTotp($usuario, $codigo)) {
            throw new RuntimeException('Ese código no es válido. Revisa la hora de tu dispositivo.');
        }

        $codigos = [];

        for ($i = 0; $i < self::CODIGOS_RECUPERACION; $i++) {
            $codigos[] = strtoupper(bin2hex(random_bytes(5)));
        }

        $usuario->update([
            'dos_factores_confirmado_en' => Carbon::now(),
            'dos_factores_recuperacion' => Crypt::encryptString(implode('|', $codigos)),
        ]);

        return $codigos;
    }

    /**
     * Verifica un código al entrar: TOTP o uno de recuperación.
     *
     * Los de recuperación son de UN SOLO USO: se consume el que se usó y los
     * demás siguen sirviendo. Dejarlos reutilizables convertiría una captura de
     * pantalla en una llave permanente.
     */
    public function verificar(User $usuario, string $codigo): bool
    {
        if ($this->verificaTotp($usuario, $codigo)) {
            return true;
        }

        return $this->consumirRecuperacion($usuario, $codigo);
    }

    public function urlDeAlta(User $usuario, string $secreto): string
    {
        return $this->totp->getQRCodeUrl(
            (string) config('app.name', 'Mentia'),
            $usuario->email,
            $secreto,
        );
    }

    private function verificaTotp(User $usuario, string $codigo): bool
    {
        if ($usuario->dos_factores_secreto === null) {
            return false;
        }

        return $this->totp->verifyKey(
            Crypt::decryptString($usuario->dos_factores_secreto),
            $codigo,
        );
    }

    private function consumirRecuperacion(User $usuario, string $codigo): bool
    {
        if ($usuario->dos_factores_recuperacion === null) {
            return false;
        }

        $codigos = explode('|', Crypt::decryptString($usuario->dos_factores_recuperacion));
        $normalizado = strtoupper(trim($codigo));

        $indice = array_search($normalizado, $codigos, true);

        if ($indice === false) {
            return false;
        }

        unset($codigos[$indice]);

        $usuario->update([
            'dos_factores_recuperacion' => $codigos === []
                ? null
                : Crypt::encryptString(implode('|', $codigos)),
        ]);

        return true;
    }
}
