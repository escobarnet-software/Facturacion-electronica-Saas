<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Usuario;
use App\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Cambia la empresa activa del usuario (por ejemplo, un contador con varias empresas). */
final class EmpresaActivaController
{
    public function __invoke(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $datos = $request->validate(['empresa_id' => ['required', 'integer']]);
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $empresaId = (int) $datos['empresa_id'];

        if (! $usuario->perteneceA($empresaId)) {
            abort(403, 'No pertenece a esa empresa.');
        }

        $usuario->forceFill(['empresa_activa_id' => $empresaId])->save();
        $tenancy->establecer($empresaId);
        Bitacora::registrar('empresa_activa.cambiada', $usuario);

        return redirect()->route('panel')->with('aviso', 'Empresa activa cambiada.');
    }
}
