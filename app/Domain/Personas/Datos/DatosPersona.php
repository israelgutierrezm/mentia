<?php

declare(strict_types=1);

namespace App\Domain\Personas\Datos;

use Illuminate\Support\Carbon;

/**
 * Los datos de alta de una persona, ya validados.
 *
 * Existe para que el servicio de dominio no reciba un arreglo suelto: un
 * `array $datos` obliga a cada servicio a volver a comprobar qué trae, y ahí
 * es donde se cuela un alta sin fecha de nacimiento —que es el insumo de todos
 * los baremos por edad—.
 */
final readonly class DatosPersona
{
    public function __construct(
        public string $nombres,
        public string $primerApellido,
        public ?string $segundoApellido,
        public Carbon $fechaNacimiento,
        public string $sexoRegistral,
        public ?string $curp = null,
        public ?string $matricula = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validados
     */
    public static function desdeValidados(array $validados): self
    {
        /** @var string $nombres */
        $nombres = $validados['nombres'];
        /** @var string $primerApellido */
        $primerApellido = $validados['primer_apellido'];
        /** @var string $fecha */
        $fecha = $validados['fecha_nacimiento'];
        /** @var string $sexo */
        $sexo = $validados['sexo_registral'];

        $curp = $validados['curp'] ?? null;

        return new self(
            nombres: trim($nombres),
            primerApellido: trim($primerApellido),
            segundoApellido: isset($validados['segundo_apellido'])
                ? trim((string) $validados['segundo_apellido'])
                : null,
            fechaNacimiento: Carbon::parse($fecha),
            sexoRegistral: $sexo,
            curp: is_string($curp) && $curp !== '' ? strtoupper(trim($curp)) : null,
            matricula: isset($validados['matricula_o_num_empleado'])
                ? trim((string) $validados['matricula_o_num_empleado'])
                : null,
        );
    }
}
