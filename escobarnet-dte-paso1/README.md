# Motor DTE de Escobar Net — Paso 1: armar y validar la Factura (01)

Primer paso del motor de facturación electrónica de Escobar Net, en **Laravel**. Convierte una venta simple de la caja en una **Factura Electrónica (tipo 01, esquema versión 2)** exacta, lista para firmar y enviar a Hacienda.

Todo sigue los documentos oficiales del Ministerio de Hacienda (versión 2.0, mayo de 2026): el Manual Funcional, el Manual Tecnológico, los JSON Schemas y los catálogos.

## Qué incluye

```
app/
├── Console/Commands/DteDemoFactura.php   → comando "php artisan dte:demo-factura"
└── Dte/
    ├── Support/
    │   ├── Decimal.php           → números decimales EXACTOS (sin float, sin extensiones)
    │   ├── DteJson.php           → escribe el JSON con los montos exactos
    │   ├── NumeroALetras.php     → "SEIS CON 50/100 DÓLARES"
    │   ├── NumeroControl.php     → DTE-01-M001P001-000000000000001
    │   └── CodigoGeneracion.php  → UUID v4 en mayúsculas
    ├── Catalogos/Catalogo.php    → catálogos oficiales más usados (CAT-001 a CAT-024)
    ├── Modelos/                  → Emisor, Receptor, LineaVenta, Pago, Venta
    ├── Factura/
    │   ├── FacturaBuilder.php    → arma la Factura con todos los cálculos
    │   └── FacturaResultado.php  → JSON listo + datos para guardar
    ├── Validacion/ValidadorEsquema.php → revisa contra el JSON Schema oficial
    └── Excepciones/DteException.php    → errores con mensajes para el cajero
config/dte.php                    → URLs oficiales de la API, tiempos y reintentos
storage/app/dte/schemas/fe-f-v2.json → JSON Schema oficial de la Factura (copia exacta)
tests/Unit/Dte/                   → 30 pruebas automáticas
```

## Instalación paso a paso

Requisitos: PHP 8.2 o superior, Composer y Git.

1. **Crear el proyecto Laravel**

   ```bash
   composer create-project laravel/laravel escobarnet-dte
   cd escobarnet-dte
   ```

2. **Copiar los archivos de esta carpeta** dentro del proyecto, respetando las rutas (`app/`, `config/`, `storage/`, `tests/`).

3. **Instalar el validador de JSON Schema**

   ```bash
   composer require opis/json-schema
   ```

   Recomendado: instalar también la extensión **bcmath** de PHP (en Ubuntu: `sudo apt install php8.3-bcmath`). El motor **no** la necesita para sus cálculos, pero ayuda al validador a revisar con precisión las reglas de decimales del esquema.

4. **Agregar al archivo `.env`**

   ```env
   DTE_AMBIENTE=00
   DTE_USER_AGENT="EscobarNet-MotorDTE/1.0"
   DTE_FIRMADOR_URL=http://localhost:8113/firmardocumento/
   ```

   `00` es el ambiente de pruebas. **Nunca** cambien a `01` (producción) hasta que el cliente esté autorizado por Hacienda.

5. **Probar**

   ```bash
   php artisan dte:demo-factura   # arma y valida la factura de la pupusería
   php artisan test --filter=Dte  # corre las 30 pruebas
   ```

   Si todo está bien, el comando termina con: `✔ La factura cumple el esquema oficial fe-f-v2.json de Hacienda.`

## Cómo se usa desde el código

```php
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\{LineaVenta, Receptor, Venta};

$venta = new Venta(
    lineas: [
        new LineaVenta('Pupusa revuelta', '6', '0.75'),   // cantidad y precio como TEXTO
        new LineaVenta('Horchata', '2', '1.00'),
    ],
    receptor: Receptor::consumidorFinal('cliente@correo.com'),
    correlativo: 1,                                        // siguiente número de esa caja
    fechaHora: now('America/El_Salvador')->toDateTimeImmutable(),
);

$factura = (new FacturaBuilder($emisor, config('dte.ambiente')))->construir($venta);

$factura->json;              // JSON listo para firmar
$factura->numeroControl;     // DTE-01-M001P001-000000000000001
$factura->codigoGeneracion;  // UUID que identifica el documento
$factura->totalPagar;        // Decimal: 6.50
```

## La regla de oro: decimales exactos

En PHP, `0.1 + 0.2` con números normales (float) da `0.30000000000000004`. Por eso, en este motor:

- **Todo monto se maneja con la clase `Decimal`**, nunca con float.
- Las cantidades y precios **se reciben como texto** (`'0.75'`), no como número.
- `Decimal::of(0.75)` (con float) **da error a propósito**, para que nadie lo use por accidente.
- El JSON se escribe sin pasar por float, así que un monto como `99999999999.12345678` sale exacto.

Reglas oficiales que aplica (Manual Funcional v2.0, sección XXI):

| Regla | Dónde |
|---|---|
| Montos por línea con hasta 8 decimales | `cuerpoDocumento` |
| Montos del resumen con 2 decimales | `resumen` |
| Redondeo: si el siguiente dígito es 5 o más, sube (5.665 → 5.67) | En todo el documento |
| Holgura de 1 centavo entre líneas y resumen | Se verifica antes de entregar el JSON |
| En la Factura el precio incluye IVA: IVA = monto × 13 / 113 | Cada línea y el resumen |
| Ventas menores a $25,000 a consumidor final: sin pedir identificación | `Receptor::consumidorFinal()` |

## Qué cubre este paso y qué sigue

**Cubre:** Factura (01) de ventas gravadas, con una o varias formas de pago, consumidor final o cliente identificado, cantidades con decimales, validación de datos y del esquema oficial.

**Todavía no cubre** (siguientes pasos, en este orden):

1. **Firma:** conectar con el firmador oficial (`svfe-api-firmador` en Docker, puerto 8113).
2. **Cliente de la API de Hacienda:** autenticación (token de 24/48 horas), envío, consulta y política de reintentos de 8 segundos.
3. **Base de datos:** emisores, cajas y correlativos, documentos con su estado y sello, certificados cifrados.
4. **Crédito Fiscal (03)** y **Notas de Crédito y Débito (05, 06)**.
5. **Descuentos**, ventas exentas y no sujetas, retenciones y tributos especiales.
6. **Invalidación** y **contingencia** (evento en 24 horas y lote en 72 horas).
7. **Representación gráfica:** PDF con código QR.

## Notas importantes

- Los códigos de municipio y distrito del ejemplo son de demostración. Los reales se toman de los catálogos CAT-013 y CAT-008 del Excel oficial de Hacienda.
- El esquema `fe-f-v2.json` es una copia exacta del publicado en factura.gob.sv (noviembre de 2026). Cuando Hacienda publique una versión nueva, se reemplaza ese archivo.
- Cumplir el esquema no garantiza que los cálculos fiscales estén bien (Manual Tecnológico v2.0, 6.1). Por eso los cálculos tienen sus propias pruebas.
