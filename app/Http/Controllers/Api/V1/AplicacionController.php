<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Modelos\VersionInstrumento;
use App\Domain\Evaluaciones\Excepciones\AplicacionInvalida;
use App\Domain\Evaluaciones\Modelos\Aplicacion;
use App\Domain\Evaluaciones\Servicios\GestorTokens;
use App\Domain\Evaluaciones\Servicios\MotorAplicacion;
use App\Domain\Evaluaciones\Servicios\RegistroRespuestas;
use App\Soporte\Multitenencia\ContextoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 07 §5 — el contrato de la app.
 *
 * SIN `auth:sanctum`: quien contesta lo hace con su TOKEN de aplicación, que
 * puede no tener cuenta. El token es la credencial, y por eso viaja en el
 * cuerpo o en el encabezado y se canjea contra `GestorTokens`.
 */
class AplicacionController extends ApiV1Controller
{
    public function __construct(
        private readonly MotorAplicacion $motor,
        private readonly RegistroRespuestas $registro,
        private readonly GestorTokens $tokens,
        private readonly ContextoOrganizacion $contexto,
    ) {}

    /**
     * Canje de token + arranque. Devuelve la ESTRUCTURA, sin enunciados.
     */
    public function iniciar(Request $peticion): JsonResponse
    {
        $validado = $peticion->validate([
            'token' => ['required', 'string', 'size:64'],
            'version_instrumento_id' => ['nullable', 'integer'],
        ]);

        $destinatario = $this->tokens->canjear($validado['token']);

        if ($destinatario === null) {
            /*
             * Un solo mensaje para todos los fallos: no existe, ya se usó,
             * expiró, la asignación cerró. Distinguirlos le confirmaría a quien
             * prueba tokens al azar que acertó uno.
             */
            return response()->json([
                'type' => 'https://mentia.mx/problemas/token-invalido',
                'title' => 'Liga no válida',
                'status' => 410,
                'detail' => 'Esta liga ya no sirve. Si crees que es un error, pide una nueva.',
            ], 410, ['Content-Type' => 'application/problem+json']);
        }

        // El contexto lo fija el TOKEN, no un encabezado: quien contesta no
        // elige de qué organización es.
        $this->contexto->establecer($destinatario->asignacion->organizacion);

        $version = isset($validado['version_instrumento_id'])
            ? VersionInstrumento::query()->find($validado['version_instrumento_id'])
            : null;

        try {
            $aplicacion = $this->motor->iniciar(
                $destinatario,
                $version,
                dispositivo: substr((string) $peticion->userAgent(), 0, 255),
                ip: $peticion->ip(),
            );
        } catch (AplicacionInvalida $error) {
            throw ValidationException::withMessages(['token' => $error->getMessage()]);
        }

        return response()->json($this->motor->estructura($aplicacion), 201);
    }

    /**
     * Entrega PARCELADA. Sin esto, el instrumento completo se descargaría de
     * una sola petición.
     */
    public function reactivos(Request $peticion, Aplicacion $aplicacion, string $clave): JsonResponse
    {
        $this->contexto->establecer($aplicacion->organizacion);

        try {
            $tramo = $this->motor->reactivosDe(
                $aplicacion,
                $clave,
                (int) $peticion->query('desde', '0'),
                (int) $peticion->query('cantidad', '10'),
            );
        } catch (AplicacionInvalida $error) {
            throw ValidationException::withMessages(['bloque' => $error->getMessage()]);
        }

        return response()->json($tramo);
    }

    /**
     * Respuestas por LOTES, idempotente por `uuid_cliente`.
     */
    public function respuestas(Request $peticion, Aplicacion $aplicacion): JsonResponse
    {
        $validado = $peticion->validate([
            'respuestas' => ['required', 'array', 'min:1', 'max:100'],
            'respuestas.*.uuid_cliente' => ['required', 'uuid'],
            'respuestas.*.reactivo_codigo' => ['required', 'string', 'max:20'],
            'respuestas.*.opcion_codigo' => ['nullable', 'string', 'max:20'],
            'respuestas.*.valor_numerico' => ['nullable', 'numeric'],
            'respuestas.*.valor_texto' => ['nullable', 'string'],
            'respuestas.*.rol_ipsativo' => ['nullable', 'in:mas,menos'],
            'respuestas.*.posicion_ranking' => ['nullable', 'integer', 'min:1'],
            'respuestas.*.tiempo_respuesta_ms' => ['nullable', 'integer', 'min:0'],
            'respuestas.*.respondida_en' => ['nullable', 'date'],
            'respuestas.*.origen' => ['nullable', 'in:online,offline'],
        ]);

        $this->contexto->establecer($aplicacion->organizacion);

        try {
            $resultado = $this->registro->recibir($aplicacion, $validado['respuestas']);
        } catch (AplicacionInvalida $error) {
            throw ValidationException::withMessages(['respuestas' => $error->getMessage()]);
        }

        return response()->json($resultado);
    }

    /**
     * Estado exacto para reanudar.
     */
    public function estado(Aplicacion $aplicacion): JsonResponse
    {
        $this->contexto->establecer($aplicacion->organizacion);

        return response()->json($this->motor->estado($aplicacion));
    }

    public function pausar(Aplicacion $aplicacion): JsonResponse
    {
        $this->contexto->establecer($aplicacion->organizacion);

        try {
            $this->motor->pausar($aplicacion);
        } catch (AplicacionInvalida $error) {
            throw ValidationException::withMessages(['estado' => $error->getMessage()]);
        }

        return response()->json($this->motor->estado($aplicacion->refresh()));
    }

    public function reanudar(Aplicacion $aplicacion): JsonResponse
    {
        $this->contexto->establecer($aplicacion->organizacion);

        $this->motor->reanudar($aplicacion);

        return response()->json($this->motor->estado($aplicacion->refresh()));
    }

    /**
     * Finalizar → encola el pipeline. Responde 202: el resultado no está listo
     * todavía, y decir 200 haría que el cliente lo fuera a buscar en vano.
     */
    public function finalizar(Aplicacion $aplicacion): JsonResponse
    {
        $this->contexto->establecer($aplicacion->organizacion);

        try {
            $this->motor->finalizar($aplicacion);
        } catch (AplicacionInvalida $error) {
            throw ValidationException::withMessages(['estado' => $error->getMessage()]);
        }

        return response()->json([
            'aplicacion_uuid' => $aplicacion->uuid,
            'estado' => $aplicacion->refresh()->estado,
            'aviso' => 'La calificación quedó encolada.',
        ], 202);
    }
}
