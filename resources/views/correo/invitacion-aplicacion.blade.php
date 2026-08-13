<x-mail::message>
# Hola{{ $nombre ? ', '.$nombre : '' }}

@if ($esRecordatorio)
Te escribimos para recordarte que tienes una evaluación pendiente de contestar.
@else
Se te invitó a contestar una evaluación en línea.
@endif

@if ($sobreQuien)
La evaluación es **sobre {{ $sobreQuien }}**, y se te pide responder como
persona informante.
@endif

<x-mail::button :url="$liga">
Contestar ahora
</x-mail::button>

Esta liga es **personal y de un solo uso**, y deja de funcionar el
{{ $vence->translatedFormat('d \d\e F \d\e Y') }}. No la compartas: quien la
tenga puede contestar en tu nombre.

Si crees que recibiste este correo por error, ignóralo.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
