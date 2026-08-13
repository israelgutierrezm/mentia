<?php

declare(strict_types=1);

namespace App\Domain\Interpretacion\Servicios;

use App\Domain\Interpretacion\Contratos\RedactaBorradores;
use App\Domain\Interpretacion\Excepciones\BorradorNoRedactable;
use Illuminate\Support\Facades\Http;

/**
 * Redacta el borrador con la API de Anthropic.
 *
 * El prompt de sistema vive VERSIONADO en `config/ia.php`, no en la base: es
 * configuración que se revisa en un pull request. Un prompt editable en
 * caliente es un prompt que nadie sabe qué decía el día que se generó un
 * reporte impugnado.
 *
 * Lo que se manda es el insumo pseudonimizado que armó `ArmadorInsumoIA`. Esta
 * clase NO decide qué va dentro: recibe y manda.
 */
class RedactorAnthropic implements RedactaBorradores
{
    public function redactar(array $insumo): string
    {
        $llave = config('ia.llave');

        if (! is_string($llave) || $llave === '') {
            /*
             * Sin llave se falla RUIDOSO. Devolver texto vacío produciría un
             * reporte que parece terminado y que nadie redactó, y alguien lo
             * firmaría.
             */
            throw BorradorNoRedactable::porFaltarCredencial();
        }

        $respuesta = Http::withHeaders([
            'x-api-key' => $llave,
            'anthropic-version' => (string) config('ia.version_api'),
            'content-type' => 'application/json',
        ])
            ->timeout((int) config('ia.timeout_segundos', 60))
            ->post((string) config('ia.url'), [
                'model' => $this->modelo(),
                'max_tokens' => (int) config('ia.max_tokens', 2000),
                'system' => (string) config('ia.prompt_sistema'),
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->mensajeCon($insumo),
                ]],
            ]);

        if ($respuesta->failed()) {
            throw BorradorNoRedactable::porFalloDelProveedor($respuesta->status());
        }

        $texto = $respuesta->json('content.0.text');

        if (! is_string($texto) || trim($texto) === '') {
            throw BorradorNoRedactable::porRespuestaVacia();
        }

        return trim($texto);
    }

    public function modelo(): string
    {
        return (string) config('ia.modelo', 'claude-sonnet-5');
    }

    /**
     * @param  array<string, mixed>  $insumo
     */
    private function mensajeCon(array $insumo): string
    {
        return "Redacta el borrador del reporte integrador con estos resultados:\n\n"
            .json_encode($insumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
