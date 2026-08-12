<?php

declare(strict_types=1);

namespace Tests\Feature\Fundacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Invariantes de configuración de la fundación.
 *
 * Son decisiones de arquitectura del Doc 02 que no se pueden apagar por
 * accidente: si alguien vuelve a publicar la config de Spatie encima de la
 * nuestra, el modo teams se apagaría y el sistema seguiría arrancando —los
 * roles simplemente dejarían de estar acotados por tenant, que es una fuga de
 * datos entre organizaciones, no un error visible—.
 */
class ConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    public function test_spatie_opera_en_modo_teams_con_organizacion_id(): void
    {
        $this->assertTrue(
            config('permission.teams'),
            'spatie/laravel-permission debe operar en modo teams (Doc 02 §3).'
        );

        $this->assertSame(
            'organizacion_id',
            config('permission.column_names.team_foreign_key'),
            'El discriminador de tenant es organizacion_id, no team_id.'
        );
    }

    public function test_las_tablas_de_spatie_llevan_la_columna_organizacion_id(): void
    {
        /** @var string $llave */
        $llave = config('permission.column_names.team_foreign_key');

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $tabla) {
            /** @var string $nombre */
            $nombre = config('permission.table_names.'.$tabla);

            $this->assertTrue(
                Schema::hasColumn($nombre, $llave),
                "La tabla {$nombre} debe tener la columna {$llave}."
            );
        }
    }

    public function test_horizon_supervisa_las_cuatro_colas_del_sistema(): void
    {
        /** @var array<string, array{queue: list<string>}> $supervisores */
        $supervisores = config('horizon.defaults');

        $colas = collect($supervisores)->pluck('queue')->flatten()->all();

        foreach (['calificacion', 'alertas', 'notificaciones', 'reportes-ia'] as $cola) {
            $this->assertContains(
                $cola,
                $colas,
                "Horizon debe supervisar la cola {$cola} (Doc 02 §7)."
            );
        }
    }

    public function test_alertas_tiene_supervisor_propio(): void
    {
        /** @var array<string, array{queue: list<string>}> $supervisores */
        $supervisores = config('horizon.defaults');

        $conAlertas = collect($supervisores)
            ->filter(fn (array $config): bool => in_array('alertas', $config['queue'], true));

        $this->assertCount(
            1,
            $conAlertas,
            'La cola alertas necesita supervisor propio: compartirlo con calificacion '
            .'haría que un pipeline saturado retrase una alerta centinela.'
        );
    }

    public function test_la_interfaz_esta_en_espanol_mexicano(): void
    {
        $this->assertSame('es_MX', config('app.locale'));
        $this->assertSame('es_MX', config('app.fallback_locale'));
    }

    public function test_el_sistema_almacena_en_utc(): void
    {
        /*
         * No es un detalle de configuración: México no tiene una sola hora
         * —Tijuana y Mérida están a dos horas—, y los cronómetros de bloque y
         * los sellos de tiempo por reactivo son server-side. Fijar aquí una
         * zona local haría ambiguos unos instantes que después no se pueden
         * reparar. La zona de presentación es de la organización.
         */
        $this->assertSame('UTC', config('app.timezone'));
    }
}
