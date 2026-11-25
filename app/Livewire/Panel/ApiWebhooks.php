<?php

namespace App\Livewire\Panel;

use App\Models\Bitacora;
use App\Models\Empresa;
use App\Models\TokenApi;
use App\Models\Webhook;
use App\Models\WebhookEntrega;
use App\Tenancy\Tenancy;
use App\Webhooks\DestinoWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Tokens de la API (con permisos) y webhooks firmados de la empresa. Solo el dueño. */
#[Layout('layouts.panel')]
#[Title('API y webhooks')]
class ApiWebhooks extends Component
{
    public string $nombreToken = '';

    /** @var list<string> */
    public array $permisos = ['dte:emitir', 'dte:leer'];

    public string $urlWebhook = '';

    /** @var list<string> */
    public array $eventos = ['dte.transmitido', 'dte.rechazado'];

    /** Se muestra UNA sola vez (token o secreto recién creado). */
    public ?string $secretoNuevo = null;

    public ?string $error = null;

    public function mount(): void
    {
        Gate::authorize('administrar-empresa');
    }

    public function crearToken(Tenancy $tenancy): void
    {
        Gate::authorize('administrar-empresa');
        $this->validate([
            'nombreToken' => 'required|string|max:100',
            'permisos' => 'required|array|min:1',
            'permisos.*' => 'in:'.implode(',', array_keys(TokenApi::PERMISOS)),
        ]);
        $empresa = Empresa::query()->findOrFail($tenancy->empresaId());
        $nuevo = $empresa->createToken($this->nombreToken, array_values($this->permisos));
        Bitacora::registrar('api.token_creado', $nuevo->accessToken, ['nombre' => $this->nombreToken, 'permisos' => $this->permisos]);
        $this->secretoNuevo = $nuevo->plainTextToken;
        $this->nombreToken = '';
    }

    public function revocarToken(int $id, Tenancy $tenancy): void
    {
        Gate::authorize('administrar-empresa');
        $token = Empresa::query()->findOrFail($tenancy->empresaId())->tokens()->whereKey($id)->firstOrFail();
        Bitacora::registrar('api.token_revocado', $token, ['nombre' => $token->name]);
        $token->delete();
    }

    public function crearWebhook(): void
    {
        Gate::authorize('administrar-empresa');
        $this->error = null;
        $this->validate([
            'urlWebhook' => 'required|url|max:500',
            'eventos' => 'required|array|min:1',
            'eventos.*' => 'in:'.implode(',', array_keys(Webhook::EVENTOS)),
        ]);
        if (($motivo = DestinoWebhook::rechazo($this->urlWebhook)) !== null) {
            $this->error = $motivo;

            return;
        }
        $secreto = 'whsec_'.Str::random(40);
        $webhook = Webhook::query()->create(['url' => $this->urlWebhook, 'secreto' => $secreto, 'eventos' => array_values($this->eventos), 'activo' => true]);
        Bitacora::registrar('webhook.creado', $webhook, ['url' => $this->urlWebhook, 'eventos' => $this->eventos]);
        $this->secretoNuevo = $secreto;
        $this->urlWebhook = '';
    }

    public function eliminarWebhook(int $id): void
    {
        Gate::authorize('administrar-empresa');
        $webhook = Webhook::query()->findOrFail($id);
        Bitacora::registrar('webhook.eliminado', $webhook, ['url' => $webhook->url]);
        $webhook->delete();
    }

    public function render(Tenancy $tenancy): View
    {
        return view('livewire.panel.api-webhooks', [
            'tokens' => Empresa::query()->findOrFail($tenancy->empresaId())->tokens()->orderByDesc('id')->get(),
            'webhooks' => Webhook::query()->orderByDesc('id')->get(),
            'entregas' => WebhookEntrega::query()->orderByDesc('id')->limit(20)->get(),
            'catalogoPermisos' => TokenApi::PERMISOS,
            'catalogoEventos' => Webhook::EVENTOS,
        ]);
    }
}
