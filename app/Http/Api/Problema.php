<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errores de la API en RFC 7807 (Doc 07, encabezado).
 *
 * Un solo formato para toda la API: `type, title, status, detail, errors{}`
 * con content-type `application/problem+json`. Que cada endpoint invente su
 * propia forma de fallar es lo que obliga al cliente —la app Flutter, un ATS
 * ajeno— a programar un caso por endpoint.
 *
 * El `detail` se cuida: un 403 no dice QUÉ permiso faltó ni confirma que el
 * recurso exista. En un sistema donde el recurso es el expediente psicológico
 * de una persona, "no tienes permiso sobre la persona X" ya filtra que X está
 * evaluada aquí (Doc 06).
 */
class Problema
{
    /**
     * URI base de los tipos de problema. No resuelve todavía; es un
     * identificador estable, que es lo que pide la RFC.
     */
    private const TIPO_BASE = 'https://mentia.mx/problemas/';

    public static function desde(Throwable $excepcion, Request $peticion): JsonResponse
    {
        [$estado, $tipo, $titulo, $detalle, $errores] = self::clasificar($excepcion, $peticion);

        $cuerpo = [
            'type' => self::TIPO_BASE.$tipo,
            'title' => $titulo,
            'status' => $estado,
            'detail' => $detalle,
        ];

        if ($errores !== []) {
            $cuerpo['errors'] = $errores;
        }

        return response()
            ->json($cuerpo, $estado, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string, 4: array<string, list<string>>}
     */
    private static function clasificar(Throwable $excepcion, Request $peticion): array
    {
        if ($excepcion instanceof ValidationException) {
            return [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validacion',
                'Datos inválidos',
                'La petición no cumple con el contrato del endpoint.',
                $excepcion->errors(),
            ];
        }

        if ($excepcion instanceof AuthenticationException) {
            return [
                Response::HTTP_UNAUTHORIZED,
                'no-autenticado',
                'No autenticado',
                'Falta un token válido en el encabezado Authorization.',
                [],
            ];
        }

        if ($excepcion instanceof AuthorizationException) {
            return [
                Response::HTTP_FORBIDDEN,
                'sin-acceso',
                'Sin acceso',
                'Tu rol activo no alcanza este recurso.',
                [],
            ];
        }

        if ($excepcion instanceof ModelNotFoundException) {
            return [
                Response::HTTP_NOT_FOUND,
                'no-encontrado',
                'No encontrado',
                'El recurso solicitado no existe o no está a tu alcance.',
                [],
            ];
        }

        if ($excepcion instanceof HttpExceptionInterface) {
            $estado = $excepcion->getStatusCode();

            return [
                $estado,
                self::tipoPorEstado($estado),
                self::tituloPorEstado($estado),
                self::detallePorEstado($estado),
                [],
            ];
        }

        /*
         * Lo no previsto: en local se deja pasar el mensaje real porque es lo
         * que hace falta para depurar; en producción NUNCA, porque el mensaje
         * de una excepción de base de datos publica nombres de tabla y a veces
         * el dato que provocó el choque.
         */
        return [
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'error-interno',
            'Error interno',
            app()->hasDebugModeEnabled()
                ? $excepcion->getMessage()
                : 'Ocurrió un error inesperado. El incidente quedó registrado.',
            [],
        ];
    }

    private static function tipoPorEstado(int $estado): string
    {
        return match ($estado) {
            401 => 'no-autenticado',
            403 => 'sin-acceso',
            404 => 'no-encontrado',
            405 => 'metodo-no-permitido',
            409 => 'conflicto',
            410 => 'expirado',
            422 => 'validacion',
            429 => 'limite-excedido',
            503 => 'no-disponible',
            default => 'error',
        };
    }

    private static function tituloPorEstado(int $estado): string
    {
        return match ($estado) {
            401 => 'No autenticado',
            403 => 'Sin acceso',
            404 => 'No encontrado',
            405 => 'Método no permitido',
            409 => 'Conflicto',
            410 => 'Expirado',
            422 => 'Datos inválidos',
            429 => 'Demasiadas peticiones',
            503 => 'Servicio no disponible',
            default => 'Error',
        };
    }

    private static function detallePorEstado(int $estado): string
    {
        return match ($estado) {
            401 => 'Falta un token válido en el encabezado Authorization.',
            403 => 'Tu rol activo no alcanza este recurso.',
            404 => 'El recurso solicitado no existe o no está a tu alcance.',
            405 => 'El método HTTP no aplica a esta ruta.',
            409 => 'El recurso cambió de estado; vuelve a consultarlo antes de reintentar.',
            410 => 'La liga o el token ya no son válidos.',
            429 => 'Excediste el límite de peticiones. Reintenta más tarde.',
            503 => 'El servicio no está disponible en este momento.',
            default => 'No se pudo completar la operación.',
        };
    }
}
