<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Escobar Net DTE')</title>
    <style>
        :root { --fondo:#f6f7f9; --texto:#1c2430; --borde:#d5dae1; --primario:#0b5cad; --error:#b42318; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, sans-serif; background:var(--fondo); color:var(--texto); }
        main { max-width: 40rem; margin: 3rem auto; padding: 0 1rem; }
        .tarjeta { background:#fff; border:1px solid var(--borde); border-radius:.5rem; padding:1.5rem; }
        label { display:block; margin:.75rem 0 .25rem; font-weight:600; }
        input, select { width:100%; padding:.6rem; border:1px solid var(--borde); border-radius:.375rem; font-size:1rem; }
        button { margin-top:1rem; padding:.6rem 1rem; border:0; border-radius:.375rem; background:var(--primario); color:#fff; font-size:1rem; cursor:pointer; }
        .aviso { background:#eef4fb; border:1px solid #b6d0ee; padding:.75rem; border-radius:.375rem; margin-bottom:1rem; }
        .error { color:var(--error); margin:.25rem 0; }
    </style>
</head>
<body>
<main>
    @if (session('aviso'))
        <div class="aviso" role="status">{{ session('aviso') }}</div>
    @endif
    @if ($errors->any())
        <div class="aviso" role="alert">
            @foreach ($errors->all() as $error)
                <p class="error">{{ $error }}</p>
            @endforeach
        </div>
    @endif
    @yield('contenido')
</main>
</body>
</html>
