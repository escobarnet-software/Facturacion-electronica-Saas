<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Plataforma\Soporte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** El superadmin entra a una empresa como soporte (solo con permiso temporal vigente) y sale. */
final class SoporteController
{
    public function entrar(int $empresa, Soporte $soporte): RedirectResponse
    {
        Gate::authorize('superadmin');
        /** @var Usuario $usuario */
        $usuario = auth()->user();
        abort_unless($soporte->entrar($usuario, $empresa), 403, 'La empresa no ha dado permiso de soporte o el permiso venció.');

        return redirect()->route('panel.documentos');
    }

    public function salir(Soporte $soporte): RedirectResponse
    {
        $soporte->salir();

        return redirect()->route('admin.empresas');
    }
}
