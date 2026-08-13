<?php

declare(strict_types=1);

namespace App\Domain\Consentimientos\Modelos;

use App\Soporte\BaseDatos\Modelo;

/**
 * La FINALIDAD del consentimiento.
 *
 * Existe como catálogo porque la LFPDPPP exige consentimiento POR FINALIDAD
 * (Doc 06 §3): tratamiento general no es lo mismo que aplicación laboral, ni
 * que compartir el expediente con otro tenant. Cada uno se otorga y se revoca
 * por separado.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string|null $descripcion
 */
class TipoConsentimiento extends Modelo
{
    protected $table = 'tipos_consentimiento';

    protected $fillable = ['clave', 'nombre', 'descripcion'];

    public const TRATAMIENTO = 'tratamiento_datos_sensibles';

    public const EDUCATIVA = 'aplicacion_educativa';

    public const LABORAL = 'aplicacion_laboral';

    public const CLINICA = 'aplicacion_clinica';

    public const COMPARTICION = 'comparticion_entre_tenants';

    public const CONTACTO = 'contacto';
}
