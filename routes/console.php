<?php

declare(strict_types=1);

use App\Jobs\EnviarRecordatoriosProgramados;
use App\Jobs\MarcarVencimientos;
use App\Jobs\TransitarMayoriaEdad;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Mantenimiento programado
|--------------------------------------------------------------------------
|
| De madrugada, en la zona de la plataforma. Ninguno de los dos es lo que
| protege el acceso —las vigencias se resuelven en vivo— así que una corrida
| fallida no abre puertas: sólo deja los estados desfasados hasta la siguiente.
|
*/

Schedule::job(new TransitarMayoriaEdad)
    ->dailyAt('03:00')
    ->name('transicion-mayoria-edad')
    ->withoutOverlapping();

Schedule::job(new MarcarVencimientos)
    ->dailyAt('03:30')
    ->name('marcar-vencimientos')
    ->withoutOverlapping();

/*
 * Recordatorios a media mañana, no de madrugada: un correo que llega a las
 * 03:40 queda sepultado bajo el resto de la bandeja antes de que nadie lo
 * abra. El servicio decide a quién le toca —cadencia, tope y ventana—, así
 * que correrlo de más no manda nada de más.
 */
Schedule::job(new EnviarRecordatoriosProgramados)
    ->dailyAt('10:00')
    ->name('recordatorios-programados')
    ->withoutOverlapping();
