<?php

declare(strict_types=1);

namespace App\Domain\Accesos\Datos;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * El resultado de AccesoService::autorizar().
 *
 * Es un OBJETO y no un booleano por dos razones: el motivo tiene que llegar a
 * la bitácora, y quien llama a veces necesita saber por qué se negó sin que
 * eso implique enseñárselo al usuario (Doc 06: un 403 no dice qué permiso
 * faltó ni confirma que el recurso exista).
 */
final readonly class DecisionAcceso
{
    private function __construct(
        public bool $permitido,
        public string $motivo,
        public ?Dimension $dimension,
    ) {}

    public static function permitir(string $motivo = 'Autorizado.'): self
    {
        return new self(true, $motivo, null);
    }

    public static function negar(Dimension $dimension, ?string $motivo = null): self
    {
        return new self(false, $motivo ?? $dimension->motivoPorOmision(), $dimension);
    }

    public function denegado(): bool
    {
        return ! $this->permitido;
    }

    /**
     * Convierte la decisión en un 403 cuando quien llama prefiere reventar.
     *
     * El mensaje que sale al usuario es genérico A PROPÓSITO: el motivo real
     * ya quedó en bitácora, y decirle a quien pregunta "esa persona está fuera
     * de tu alcance" le confirma que esa persona existe y está evaluada aquí.
     */
    public function oFallar(): void
    {
        if ($this->permitido) {
            return;
        }

        throw new AuthorizationException('No tienes acceso a este recurso.');
    }
}
