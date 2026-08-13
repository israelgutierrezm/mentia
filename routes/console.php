<?php

declare(strict_types=1);

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
