<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Escobar Net DTE')</title>
    @vite(['resources/css/app.css'])
    <style>
        .acceso .tarjeta { background:#fff; border:1px solid #e2e8f0; border-radius:1rem; padding:2rem; box-shadow:0 10px 30px -12px rgba(15,27,45,.25); }
        .acceso h1 { font-size:1.5rem; font-weight:700; color:#0f1b2d; margin-bottom:.25rem; }
        .acceso label { display:block; margin:1rem 0 .35rem; font-size:.875rem; font-weight:600; color:#334155; }
        .acceso input, .acceso select { width:100%; padding:.65rem .8rem; border:1px solid #cbd5e1; border-radius:.6rem; font-size:.95rem; background:#fff; }
        .acceso input:focus, .acceso select:focus { outline:none; border-color:#2f6fea; box-shadow:0 0 0 4px #d9e8ff; }
        .acceso button { margin-top:1.5rem; width:100%; padding:.75rem 1rem; border:0; border-radius:.6rem; background:#1f56d6; color:#fff; font-weight:600; font-size:.95rem; cursor:pointer; box-shadow:0 6px 16px -6px rgba(31,86,214,.6); }
        .acceso button:hover { background:#1a45ad; }
        .acceso .aviso { background:#eef5ff; border:1px solid #bcd6ff; color:#1b3c89; padding:.75rem 1rem; border-radius:.6rem; margin-bottom:1rem; font-size:.875rem; }
        .acceso .error, .acceso [role=alert] p { color:#be123c; margin:.25rem 0; font-size:.875rem; }
        .acceso p { color:#475569; font-size:.9rem; }
        .acceso img, .acceso svg { max-width:100%; }
        .acceso code { background:#f1f5f9; padding:.1rem .35rem; border-radius:.3rem; }
    </style>
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-2">
    <div class="relative hidden overflow-hidden bg-gradient-to-br from-marca-900 via-marca-800 to-marca-600 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-xl bg-white/15"><x-icono nombre="escudo" clase="size-6"/></div>
            <div><div class="text-lg font-bold">Escobar Net</div><div class="text-xs text-marca-200">Facturación electrónica · El Salvador</div></div>
        </div>
        <div class="max-w-md">
            <h2 class="!text-3xl !font-bold leading-tight !text-white">Emita sus documentos tributarios electrónicos sin complicaciones.</h2>
            <ul class="mt-8 space-y-4 text-marca-100">
                <li class="flex gap-3"><x-icono nombre="ok" clase="size-6 shrink-0 text-emerald-300"/>Factura, Crédito Fiscal y los demás DTE, validados con los esquemas oficiales de Hacienda.</li>
                <li class="flex gap-3"><x-icono nombre="ok" clase="size-6 shrink-0 text-emerald-300"/>Firma, transmisión y sello automáticos; contingencia sin detener sus ventas.</li>
                <li class="flex gap-3"><x-icono nombre="ok" clase="size-6 shrink-0 text-emerald-300"/>Sus credenciales cifradas y sus datos aislados de las demás empresas.</li>
            </ul>
        </div>
        <div class="text-xs text-marca-200">© {{ date('Y') }} Escobar Net</div>
        <div class="pointer-events-none absolute -right-24 -bottom-24 size-96 rounded-full bg-white/5"></div>
        <div class="pointer-events-none absolute -top-16 right-24 size-64 rounded-full bg-white/5"></div>
    </div>
    <div class="flex items-center justify-center bg-slate-50 px-4 py-12">
        <main class="acceso w-full max-w-md">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="flex size-10 items-center justify-center rounded-xl bg-marca-600 text-white"><x-icono nombre="escudo" clase="size-6"/></div>
                <div><div class="font-bold text-tinta">Escobar Net</div><div class="text-xs text-slate-500">Facturación electrónica</div></div>
            </div>
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
    </div>
</div>
</body>
</html>
