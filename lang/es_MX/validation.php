<?php

declare(strict_types=1);

/*
 * Mensajes de validación en español mexicano, con acentuación completa
 * (Doc 08, definición de terminado).
 *
 * Tuteo, no "usted": el sistema lo usan orientadores, psicólogas, alumnos de
 * secundaria y padres de familia. El "usted" institucional lee distante en las
 * pantallas del titular, que son las que más se leen.
 */

return [
    'accepted' => 'Debes aceptar :attribute.',
    'accepted_if' => 'Debes aceptar :attribute cuando :other sea :value.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute sólo puede contener letras.',
    'alpha_dash' => ':attribute sólo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute sólo puede contener letras y números.',
    'any_of' => ':attribute no es válido.',
    'array' => ':attribute debe ser una lista.',
    'ascii' => ':attribute sólo puede contener caracteres alfanuméricos y símbolos de un byte.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute debe ser verdadero o falso.',
    'can' => ':attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'A :attribute le falta un valor requerido.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar :attribute.',
    'declined_if' => 'Debes rechazar :attribute cuando :other sea :value.',
    'different' => ':attribute y :other deben ser diferentes.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene dimensiones de imagen no válidas.',
    'distinct' => ':attribute tiene un valor duplicado.',
    'doesnt_end_with' => ':attribute no debe terminar con ninguno de estos valores: :values.',
    'doesnt_start_with' => ':attribute no debe empezar con ninguno de estos valores: :values.',
    'email' => ':attribute no es un correo electrónico válido.',
    'ends_with' => ':attribute debe terminar con alguno de estos valores: :values.',
    'enum' => ':attribute no es un valor válido.',
    'exists' => ':attribute no existe.',
    'extensions' => ':attribute debe tener alguna de estas extensiones: :values.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => ':attribute no puede quedar vacío.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => ':attribute debe ser mayor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => ':attribute debe ser un color hexadecimal válido.',
    'image' => ':attribute debe ser una imagen.',
    'in' => ':attribute no es un valor válido.',
    'in_array' => ':attribute no existe en :other.',
    'in_array_keys' => ':attribute debe contener al menos una de estas llaves: :values.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',
    'list' => ':attribute debe ser una lista.',
    'lowercase' => ':attribute debe ir en minúsculas.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no debe tener más de :value elementos.',
        'file' => ':attribute debe pesar :value kilobytes o menos.',
        'numeric' => ':attribute debe ser menor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => ':attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => ':attribute no debe tener más de :max elementos.',
        'file' => ':attribute no debe pesar más de :max kilobytes.',
        'numeric' => ':attribute no debe ser mayor que :max.',
        'string' => ':attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => ':attribute no debe tener más de :max dígitos.',
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => ':attribute debe tener al menos :min dígitos.',
    'missing' => ':attribute no debe venir en la petición.',
    'missing_if' => ':attribute no debe venir cuando :other sea :value.',
    'missing_unless' => ':attribute no debe venir a menos que :other sea :value.',
    'missing_with' => ':attribute no debe venir cuando :values esté presente.',
    'missing_with_all' => ':attribute no debe venir cuando :values estén presentes.',
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => ':attribute no es un valor válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'password' => [
        'letters' => 'La contraseña debe contener al menos una letra.',
        'mixed' => 'La contraseña debe contener al menos una mayúscula y una minúscula.',
        'numbers' => 'La contraseña debe contener al menos un número.',
        'symbols' => 'La contraseña debe contener al menos un símbolo.',
        'uncompromised' => 'Esa contraseña apareció en una filtración de datos. Elige otra.',
    ],
    'present' => ':attribute debe venir en la petición.',
    'present_if' => ':attribute debe venir cuando :other sea :value.',
    'present_unless' => ':attribute debe venir a menos que :other sea :value.',
    'present_with' => ':attribute debe venir cuando :values esté presente.',
    'present_with_all' => ':attribute debe venir cuando :values estén presentes.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => ':attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => ':attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => ':attribute no está permitido a menos que :other esté en :values.',
    'prohibits' => ':attribute impide que :other venga en la petición.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':attribute es obligatorio.',
    'required_array_keys' => ':attribute debe contener las llaves: :values.',
    'required_if' => ':attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => ':attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => ':attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => ':attribute es obligatorio a menos que :other esté en :values.',
    'required_with' => ':attribute es obligatorio cuando :values está presente.',
    'required_with_all' => ':attribute es obligatorio cuando :values están presentes.',
    'required_without' => ':attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => ':attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe contener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe empezar con alguno de estos valores: :values.',
    'string' => ':attribute debe ser texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => ':attribute debe ir en mayúsculas.',
    'url' => ':attribute debe ser una URL válida.',
    'ulid' => ':attribute debe ser un ULID válido.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
     * Mensajes por campo. Aquí van las reglas del dominio que necesitan
     * explicar POR QUÉ, no sólo qué falló: "la CURP no tiene 18 caracteres" no
     * ayuda a quien está capturando el acta de nacimiento en la mano.
     */
    'custom' => [
        'curp' => [
            'regex' => 'La CURP debe tener 18 caracteres con el formato oficial de RENAPO.',
        ],
    ],

    /*
     * Nombres legibles de los campos. Sin esto, el mensaje sale con el nombre
     * de la columna —"fecha_nacimiento es obligatorio"— y se ve a leguas que
     * es la base de datos hablando.
     */
    'attributes' => [
        'apellido_materno' => 'apellido materno',
        'apellido_paterno' => 'apellido paterno',
        'contrasena' => 'la contraseña',
        'correo' => 'el correo electrónico',
        'curp' => 'la CURP',
        'fecha_nacimiento' => 'la fecha de nacimiento',
        'nombre' => 'el nombre',
        'organizacion_id' => 'la organización',
        'telefono' => 'el teléfono',
    ],
];
