@extends('mail.layout')
@section('body')
<p>Hola, {{ $name }}:</p>
<p>Te dieron de alta como <strong>{{ $roleLabel }}</strong> de <strong>{{ $communityName }}</strong>. Para activar tu cuenta usa este PIN:</p>
<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;margin:20px 0">{{ $pin }}</p>
<p>Al activarla vas a crear tu contraseña y subir una foto tuya o de tu identificación.</p>
<p style="text-align:center;margin:24px 0"><a href="{{ $link }}" style="background:#0B6FA4;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold">Activar mi cuenta</a></p>
<p>También puedes hacerlo desde la app de escritorio, en «Activar mi cuenta». El PIN sirve una sola vez y vence en {{ $hours }} horas.</p>
@endsection
