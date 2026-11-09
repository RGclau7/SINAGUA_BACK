@extends('mail.layout')
@section('body')
<p>Hola, {{ $name }}:</p>
<p>Alguien (seguramente tú) entró a SINAGUA con tu contraseña en un equipo nuevo. Para confirmar que eres tú, escribe este código en la app:</p>
<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;margin:20px 0">{{ $pin }}</p>
<p>Vence en {{ $minutes }} minutos y sirve una sola vez. En ese equipo ya no te lo volveremos a pedir.</p>
<p>Si no fuiste tú, cambia tu contraseña y avisa al superadministrador.</p>
@endsection
