<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc 06 §4 — 2FA obligatoria para roles de sensibilidad 3–4.
 *
 * Quien puede abrir el expediente clínico de un menor entra con dos factores.
 * No es una preferencia del usuario: es un requisito del ROL, y por eso el
 * sistema puede bloquear el acceso de quien todavía no la activó en vez de
 * limitarse a sugerirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            /*
             * El secreto va CIFRADO por la aplicación, igual que las notas
             * profesionales: quien lea un respaldo de la base no debe poder
             * generar los códigos de nadie.
             */
            $tabla->text('dos_factores_secreto')->nullable()->after('password');

            $tabla->dateTime('dos_factores_confirmado_en', precision: 3)
                ->nullable()->after('dos_factores_secreto');

            /*
             * Los códigos de recuperación, cifrados y de un solo uso. Sin
             * ellos, perder el teléfono sería perder la cuenta — y en un
             * sistema donde el acceso lo da la organización, eso significa una
             * llamada a soporte para que alguien desactive la 2FA por fuera,
             * que es exactamente el agujero que la 2FA venía a tapar.
             */
            $tabla->text('dos_factores_recuperacion')->nullable()
                ->after('dos_factores_confirmado_en');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla): void {
            $tabla->dropColumn([
                'dos_factores_secreto',
                'dos_factores_confirmado_en',
                'dos_factores_recuperacion',
            ]);
        });
    }
};
