{{--
    Este correo NO lleva el contenido de la respuesta, ni el nombre de la
    persona evaluada, ni el del instrumento. Un correo viaja por servidores que
    no son de nadie y se queda en la bandeja de entrada de quien lo reciba para
    siempre. El detalle se ve dentro del sistema, donde el acceso pasa por
    AccesoService y queda en bitácora.
--}}
<x-mail::message>
# Hola{{ $nombre ? ', '.$nombre : '' }}

@if ($severidad === 'critica')
Se generó una **alerta crítica** que requiere atención inmediata conforme al
protocolo de actuación de tu organización.
@elseif ($severidad === 'alta')
Se generó una **alerta de riesgo** que requiere tu atención.
@else
Se generó un aviso de seguimiento.
@endif

Ocurrió el {{ $creadaEn->translatedFormat('d \d\e F \d\e Y, H:i') }}.

<x-mail::button :url="$liga">
Abrir el centro de alertas
</x-mail::button>

El detalle sólo se puede ver dentro del sistema. La alerta permanece abierta
hasta que se registre cómo se atendió.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
