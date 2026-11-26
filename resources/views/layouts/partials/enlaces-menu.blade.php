@foreach ($enlaces as $enlace)
    @php([$ruta, $icono, $texto] = $enlace)
    @php($aviso = $enlace[4] ?? null)
    @php($activo = request()->routeIs($ruta))
    <a href="{{ route($ruta) }}" @class(['nav-enlace', 'nav-enlace-activo' => $activo]) @if ($activo) aria-current="page" @endif>
        <x-icono :nombre="$icono" clase="size-5 shrink-0 opacity-90"/>
        <span class="truncate">{{ $texto }}</span>
        @if ($aviso)
            <span @class(['ml-auto shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold', 'bg-rose-500 text-white' => $aviso[1] === 'rojo', 'bg-amber-400 text-amber-950' => $aviso[1] === 'ambar'])>{{ $aviso[0] }}</span>
        @endif
    </a>
@endforeach
