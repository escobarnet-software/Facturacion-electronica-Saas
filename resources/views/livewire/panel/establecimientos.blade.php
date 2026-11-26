<div class="space-y-4">
    <h1 >Establecimientos y puntos de venta</h1>
    <p class="-mt-2 text-sm text-slate-500">Casa matriz, sucursales y cajas, con los códigos que les asignó Hacienda.</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <form wire:submit="crearEstablecimiento" class="space-y-2 card">
            <h2 class="font-semibold">Nuevo establecimiento</h2>
            <label class="block">Tipo<select wire:model="establecimiento.tipo" class="mt-1 w-full "><option value="02">Casa matriz</option><option value="01">Sucursal</option><option value="04">Bodega</option><option value="07">Predio o patio</option><option value="20">Otro</option></select></label>
            <label class="block">Nombre<input wire:model="establecimiento.nombre" class="mt-1 w-full "></label>
            <label class="block">Código de Hacienda<input wire:model="establecimiento.cod_estable_mh" class="mt-1 w-full " placeholder="M001"></label>
            @error('establecimiento.*')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button class="btn-primario">Agregar</button>
        </form>
        <form wire:submit="crearPuntoVenta" class="space-y-2 card">
            <h2 class="font-semibold">Nuevo punto de venta</h2>
            <label class="block">Establecimiento<select wire:model="puntoVenta.establecimiento_id" class="mt-1 w-full "><option value="">Elija…</option>@foreach ($establecimientos as $e)<option value="{{ $e->id }}">{{ $e->cod_estable_mh }} · {{ $e->nombre }}</option>@endforeach</select></label>
            <label class="block">Nombre<input wire:model="puntoVenta.nombre" class="mt-1 w-full "></label>
            <label class="block">Código de Hacienda<input wire:model="puntoVenta.cod_punto_venta_mh" class="mt-1 w-full " placeholder="P001"></label>
            @error('puntoVenta.*')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button class="btn-primario">Agregar</button>
        </form>
    </div>
    @foreach ($establecimientos as $e)
        <div class="card"><strong>{{ $e->cod_estable_mh }}</strong> · {{ $e->nombre }}
            <ul class="ml-4 list-disc text-sm">@foreach ($e->puntosVenta as $pv)<li>{{ $pv->cod_punto_venta_mh }} · {{ $pv->nombre }}</li>@endforeach</ul>
        </div>
    @endforeach
</div>
