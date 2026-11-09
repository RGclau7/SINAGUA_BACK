<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Activar mi cuenta · SINAGUA</title>
  <style>
    :root { color-scheme: light dark; --azul:#0B6FA4; --fondo:#f2f5f8; --texto:#1d2733; --gris:#6b7785; --linea:#d5dde5; --rojo:#b42318; --rojo-suave:#fdecea; --verde:#1b7a43; --verde-suave:#e6f4ec; }
    @media (prefers-color-scheme: dark) { :root { --fondo:#11171d; --texto:#e7edf3; --gris:#9aa7b4; --linea:#2c3742; --rojo-suave:#3a1c1a; --verde-suave:#173325; } .tarjeta { background:#1a222b !important; } input { background:#11171d; color:var(--texto); } }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--fondo); color:var(--texto); font-family:-apple-system,"Segoe UI",Helvetica,Arial,sans-serif; }
    main { max-width:440px; margin:0 auto; padding:24px 16px 48px; }
    .marca { color:var(--azul); font-weight:700; font-size:20px; letter-spacing:1px; margin:8px 0 18px; }
    .tarjeta { background:#fff; border-radius:12px; padding:22px; box-shadow:0 4px 18px rgba(0,0,0,.06); }
    h1 { font-size:22px; margin:0 0 6px; }
    p.ayuda { color:var(--gris); margin:0 0 18px; line-height:1.4; }
    label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
    input[type=email], input[type=text], input[type=password] { width:100%; padding:11px 12px; border:1px solid var(--linea); border-radius:8px; font-size:16px; }
    input[type=file] { width:100%; font-size:15px; }
    .pin { letter-spacing:6px; font-size:20px !important; text-align:center; }
    button { width:100%; margin-top:20px; padding:13px; border:0; border-radius:8px; background:var(--azul); color:#fff; font-size:16px; font-weight:700; cursor:pointer; }
    .errores { background:var(--rojo-suave); color:var(--rojo); border-radius:8px; padding:12px 14px; margin-bottom:6px; }
    .errores ul { margin:0; padding-left:18px; }
    .listo { background:var(--verde-suave); color:var(--verde); border-radius:8px; padding:14px; line-height:1.4; }
    small { color:var(--gris); }
  </style>
</head>
<body>
<main>
  <div class="marca">SINAGUA</div>
  <div class="tarjeta">
  @if ($done)
    <h1>¡Listo, {{ $name }}!</h1>
    <div class="listo">Tu cuenta ya está activa. Entra a la app de SINAGUA con tu correo <strong>{{ $email }}</strong> y la contraseña que acabas de crear.</div>
  @else
    <h1>Activar mi cuenta</h1>
    <p class="ayuda">Escribe el PIN de 6 números que te llegó al correo, crea tu contraseña y sube tu foto (o una foto de tu identificación).</p>
    @if (count($errors_list) > 0)
      <div class="errores" role="alert"><ul>@foreach ($errors_list as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="post" action="{{ url('/activar') }}" enctype="multipart/form-data">
      @csrf
      <label for="email">Correo</label>
      <input id="email" name="email" type="email" value="{{ $email }}" required autocomplete="email">
      <label for="pin">PIN del correo</label>
      <input id="pin" name="pin" type="text" class="pin" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
      <label for="password">Contraseña nueva <small>(mínimo 10 caracteres)</small></label>
      <input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
      <label for="password_confirmation">Repite la contraseña</label>
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" required autocomplete="new-password">
      <label for="photo">Tu foto o tu identificación <small>(JPG o PNG, máximo 5 MB)</small></label>
      <input id="photo" name="photo" type="file" accept="image/jpeg,image/png" capture="user" required>
      <button type="submit">Activar mi cuenta</button>
    </form>
  @endif
  </div>
</main>
</body>
</html>
