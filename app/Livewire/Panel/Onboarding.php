<?php

namespace App\Livewire\Panel;

use App\Dte\Credenciales\AlmacenCertificados;
use App\Dte\Pruebas\EjecutorPruebasMinimas;
use App\Dte\Validacion\ReglasHacienda as R;
use App\Jobs\EjecutarPruebasMinimas;
use App\Models\Bitacora;
use App\Models\Empresa;
use App\Models\EmpresaCredencial;
use App\Models\PruebaMinima;
use App\Models\PuntoVenta;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Asistente para que la empresa sea emisora (ESPECIFICACION §18). Lo hace el CLIENTE:
 * él genera en el portal de Hacienda su certificado y su usuario de la API, y los carga aquí,
 * en un formulario cifrado. Escobar Net nunca los pide por chat ni WhatsApp.
 */
#[Layout('layouts.panel')]
#[Title('Configuración y autorización')]
class Onboarding extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $empresa = [];

    public string $ambienteCredencial = '00';

    public ?TemporaryUploadedFile $certificado = null;

    public string $pwdApi = '';

    public string $pwdPrivada = '';

    /** @var list<string> */
    public array $tiposPrueba = ['01', '03'];

    public ?string $mensaje = null;

    public ?string $error = null;

    public function mount(): void
    {
        Gate::authorize('administrar-empresa');
        $e = $this->modelo();
        $d = (array) $e->getAttribute('direccion');
        $this->empresa = [
            'nrc' => $e->nrc, 'razon_social' => $e->razon_social, 'nombre_comercial' => $e->nombre_comercial,
            'cod_actividad' => $e->getAttribute('cod_actividad'), 'desc_actividad' => $e->getAttribute('desc_actividad'),
            'telefono' => $e->getAttribute('telefono'), 'correo' => $e->getAttribute('correo'),
            'departamento' => $d['departamento'] ?? null, 'municipio' => $d['municipio'] ?? null, 'distrito' => $d['distrito'] ?? null, 'complemento' => $d['complemento'] ?? null,
            'responsable_nombre' => $e->getAttribute('responsable_nombre'), 'responsable_tipo_doc' => $e->getAttribute('responsable_tipo_doc') ?? '13', 'responsable_num_doc' => $e->getAttribute('responsable_num_doc'),
            'tipo_contribuyente' => $e->getAttribute('tipo_contribuyente'), 'agente_retencion' => (bool) $e->getAttribute('agente_retencion'), 'agente_percepcion' => (bool) $e->getAttribute('agente_percepcion'),
        ];
    }

    /**
     * Datos del EMISOR según los esquemas oficiales (sección «emisor»: todos obligatorios salvo el
     * nombre comercial) y los catálogos de Hacienda.
     *
     * @return array<string, mixed>
     */
    protected function reglasEmpresa(): array
    {
        $dep = $this->empresa['departamento'] ?? null;

        return [
            'empresa.nrc' => R::nrc(),
            'empresa.razon_social' => R::texto('nombre'),
            'empresa.nombre_comercial' => R::texto('nombre_comercial', false),
            'empresa.cod_actividad' => R::actividad(),
            'empresa.desc_actividad' => R::texto('desc_actividad'),
            'empresa.telefono' => R::telefono(),
            'empresa.correo' => R::correo(),
            'empresa.departamento' => R::departamento(),
            'empresa.municipio' => R::municipio($dep),
            'empresa.distrito' => R::distrito($dep),
            'empresa.complemento' => R::texto('complemento'),
            'empresa.responsable_nombre' => ['required', 'string', 'min:3', 'max:100'],
            'empresa.responsable_tipo_doc' => R::tipoDocumento(),
            'empresa.responsable_num_doc' => R::numeroDocumento($this->empresa['responsable_tipo_doc'] ?? null),
            'empresa.tipo_contribuyente' => 'required|in:grande,mediano,otro',
            'empresa.agente_retencion' => 'boolean', 'empresa.agente_percepcion' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    protected function mensajesEmpresa(): array
    {
        return R::mensajes(['empresa.nrc' => 'nrc', 'empresa.cod_actividad' => 'actividad', 'empresa.telefono' => 'telefono', 'empresa.correo' => 'correo',
            'empresa.departamento' => 'departamento', 'empresa.municipio' => 'municipio', 'empresa.distrito' => 'distrito',
            'empresa.responsable_num_doc' => 'documento', 'empresa.responsable_tipo_doc' => 'tipoDocumento']);
    }

    /** @return array<string, string> */
    protected function atributosEmpresa(): array
    {
        return ['empresa.razon_social' => 'razón social', 'empresa.nombre_comercial' => 'nombre comercial', 'empresa.cod_actividad' => 'actividad económica',
            'empresa.desc_actividad' => 'descripción de la actividad', 'empresa.telefono' => 'teléfono', 'empresa.correo' => 'correo', 'empresa.complemento' => 'dirección',
            'empresa.responsable_nombre' => 'nombre del responsable', 'empresa.responsable_num_doc' => 'documento del responsable'];
    }

    /** Validación en vivo de los datos de la empresa. */
    public function updated(string $propiedad): void
    {
        if (! str_starts_with($propiedad, 'empresa.')) {
            return;
        }
        $this->normalizarEmpresa();
        if ($propiedad === 'empresa.departamento') {
            $this->empresa['municipio'] = $this->empresa['distrito'] = null;
        }
        if ($propiedad === 'empresa.cod_actividad') {
            $this->empresa['desc_actividad'] = R::nombreActividad($this->empresa['cod_actividad'] ?? null) ?? $this->empresa['desc_actividad'];
        }
        $this->validateOnly($propiedad, $this->reglasEmpresa(), $this->mensajesEmpresa(), $this->atributosEmpresa());
    }

    private function normalizarEmpresa(): void
    {
        $this->empresa['nrc'] = R::soloDigitos($this->empresa['nrc'] ?? null);
        $this->empresa['responsable_num_doc'] = R::documento($this->empresa['responsable_tipo_doc'] ?? null, $this->empresa['responsable_num_doc'] ?? null);
        foreach (['razon_social', 'nombre_comercial', 'desc_actividad', 'telefono', 'correo', 'complemento', 'responsable_nombre'] as $k) {
            if (is_string($this->empresa[$k] ?? null)) {
                $this->empresa[$k] = trim($this->empresa[$k]) === '' ? null : trim($this->empresa[$k]);
            }
        }
    }

    public function guardarEmpresa(): void
    {
        Gate::authorize('administrar-empresa');
        $this->normalizarEmpresa();
        $d = $this->validate($this->reglasEmpresa(), $this->mensajesEmpresa(), $this->atributosEmpresa())['empresa'];

        $e = $this->modelo();
        $e->fill([
            ...array_diff_key($d, array_flip(['departamento', 'municipio', 'distrito', 'complemento'])),
            'direccion' => ['departamento' => $d['departamento'], 'municipio' => $d['municipio'], 'distrito' => $d['distrito'], 'complemento' => $d['complemento']],
        ])->save();
        Bitacora::registrar('empresa.datos_actualizados', $e);
        $this->mensaje = 'Datos de la empresa guardados.';
    }

    public function guardarCredenciales(AlmacenCertificados $almacen): void
    {
        Gate::authorize('administrar-empresa');
        $this->validate([
            'ambienteCredencial' => 'required|in:00,01',
            'pwdApi' => 'required|string|min:8|max:30',
            'pwdPrivada' => 'required|string|min:1|max:100',
            'certificado' => 'required|file|max:512|extensions:crt,xml',
        ], [], ['pwdApi' => 'contraseña de la API', 'pwdPrivada' => 'contraseña de la llave privada']);

        /** @var TemporaryUploadedFile $subido */
        $subido = $this->certificado;
        $contenidoCertificado = (string) file_get_contents($subido->getRealPath());
        try {
            AlmacenCertificados::validar($contenidoCertificado);
        } catch (\InvalidArgumentException $e) {
            $this->addError('certificado', $e->getMessage());

            return;
        }

        $e = $this->modelo();
        $credencial = EmpresaCredencial::query()->firstOrNew(['ambiente' => $this->ambienteCredencial]);
        $credencial->usuario_api = $e->nit;
        $credencial->establecerSecretos($this->pwdApi, $this->pwdPrivada)->save();
        $almacen->guardar($e, $credencial, $contenidoCertificado);
        Bitacora::registrar('credenciales.cargadas', $credencial, ['ambiente' => $this->ambienteCredencial]);

        // Nunca se devuelven a la pantalla.
        $this->reset('pwdApi', 'pwdPrivada', 'certificado');
        $this->mensaje = 'Credenciales del ambiente '.($this->ambienteCredencial === '00' ? 'de pruebas' : 'de producción').' cargadas y cifradas.';
    }

    public function ejecutarPruebas(): void
    {
        Gate::authorize('administrar-empresa');
        $this->error = null;
        $tipos = array_values(array_intersect($this->tiposPrueba, array_keys(EjecutorPruebasMinimas::requeridas())));
        if ($tipos === [] || ! EmpresaCredencial::query()->where('ambiente', '00')->exists() || PuntoVenta::query()->doesntExist()) {
            $this->error = 'Antes de las pruebas: cargue las credenciales de pruebas, cree un establecimiento con su punto de venta y elija los tipos.';

            return;
        }
        EjecutarPruebasMinimas::dispatch($this->modelo()->id, $tipos);
        $this->mensaje = 'Pruebas mínimas en ejecución. El avance se actualiza solo.';
    }

    public function pasarAProduccion(): void
    {
        Gate::authorize('administrar-empresa');
        $e = $this->modelo();
        if (! EmpresaCredencial::query()->where('ambiente', '01')->exists()) {
            $this->error = 'Cargue primero el certificado y el usuario de la API de PRODUCCIÓN que Hacienda le entregó al autorizarle.';

            return;
        }
        $e->forceFill(['ambiente' => '01'])->save();
        Bitacora::registrar('empresa.paso_a_produccion', $e);
        $this->mensaje = 'La empresa ya emite en producción: sus documentos tienen validez legal.';
    }

    public function render(): View
    {
        return view('livewire.panel.onboarding', [
            'modelo' => $this->modelo(),
            'credenciales' => EmpresaCredencial::query()->get()->map->resumenSeguro(),
            'tienePuntoVenta' => PuntoVenta::query()->exists(),
            'pruebas' => PruebaMinima::query()->orderBy('id')->get(),
            'tiposDisponibles' => EjecutorPruebasMinimas::requeridas(),
            'tiposDocumento' => R::opciones('CAT-022'),
            'departamentos' => R::opciones('CAT-012'),
            'municipios' => ($dep = $this->empresa['departamento'] ?? null) ? R::opciones('CAT-013', $dep) : [],
            'distritos' => $dep ? R::opciones('CAT-008', $dep) : [],
            'actividades' => R::opciones('CAT-019'),
        ]);
    }

    private function modelo(): Empresa
    {
        return Empresa::query()->findOrFail(app(Tenancy::class)->empresaId());
    }
}
