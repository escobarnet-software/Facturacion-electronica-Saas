# INICIO — Construir la plataforma SaaS de Facturación Electrónica de Escobar Net

> **Para Claude Code.** Este documento contiene **todo** lo que el equipo de Escobar Net investigó y decidió: el negocio, las reglas de Hacienda de El Salvador (Normativa DTE v2.0, el Manual Técnico de la API, el Manual Funcional, los manuales de usuario y los catálogos), la arquitectura, el plan completo y el código del Paso 1, que ya está probado.
> Tu trabajo es construir la plataforma completa **de forma autónoma**, siguiendo este documento.

---

## PARTE 0 — Instrucciones de trabajo (léelas primero)

### 0.1 Qué hacer al recibir este documento
1. **Crea la estructura del proyecto** en la carpeta actual:
   - Guarda la PARTE 1 como `CLAUDE.md`.
   - Guarda la PARTE 2 como `ESPECIFICACION.md`.
   - Guarda la PARTE 3 como `PLAN.md`.
   - Guarda cada archivo de la PARTE 4 en la ruta indicada en su título, dentro de `escobarnet-dte-paso1/`.
   - Si este documento está guardado como archivo, consérvalo en `docs/INICIO.md`.
2. Inicializa git y haz el primer commit: «docs: especificación y plan iniciales».
3. **Descarga los esquemas JSON oficiales** de todos los DTE y eventos desde la documentación técnica de https://factura.gob.sv y guárdalos en `resources/schemas/`. Si no puedes descargarlos, pídeselos al usuario una sola vez, sigue trabajando y usa el esquema de la Factura de la PARTE 4 para lo que ya existe.
4. Si existe `docs/oficial/` con los PDF y el Excel de catálogos, úsalos para resolver los puntos marcados **[VERIFICAR]** de la especificación. Si no existe, pídele al usuario el Excel de catálogos (`Catalogos.xlsx`) cuando llegues al Paso 3.
5. Ejecuta el `PLAN.md` paso a paso, del 0 al 15.

### 0.2 Modo autónomo
- **No pidas aprobación entre pasos.** Al terminar un paso:
  1. Corre las pruebas, Pint y Larastan.
  2. Verifica cada criterio de «Terminado cuando».
  3. Marca el paso en la sección «Estado» de `PLAN.md`.
  4. Haz commit («feat(paso-N): …»).
  5. Escribe un resumen de 5 líneas y **sigue con el siguiente paso**.
- **Solo te detienes** en estos casos:
  - a) Necesitas algo que únicamente el usuario puede darte: credenciales de prueba de Hacienda en el `.env`, el certificado `.crt`, el Excel de catálogos, una decisión de la lista «Decisiones pendientes» de `PLAN.md`, o una cuenta de hosting o de pasarela de pago.
  - b) Una prueba de «Terminado cuando» no pasa después de 3 intentos razonables.
  - c) La normativa es ambigua y la decisión cambia cálculos o cumplimiento legal.
  Al detenerte, explica en español sencillo qué necesitas y por qué, y propón la opción que recomiendas.
- Si un paso está bloqueado por algo externo (por ejemplo, las credenciales para la prueba real contra Hacienda), **marca esa prueba como pendiente**, deja una nota en «Estado» y **continúa** con lo que no dependa de ella. Las pruebas con `Http::fake` siempre deben pasar.
- Al terminar cada fase (A, B, C, D), dale al usuario un resumen de qué funciona, cómo probarlo (comandos y URL locales) y qué queda pendiente.

### 0.3 Reglas absolutas (resumen; el detalle está en la PARTE 1)
- **Dinero:** nunca uses `float`. Usa `Decimal`, con 8 decimales en los ítems y 2 en el resumen, redondeo HALF_UP y holgura de ±0.01.
- **Multiempresa:** `empresa_id`, scope global y Row Level Security de PostgreSQL. Una empresa jamás ve datos de otra.
- **Credenciales:** las del cliente (contraseña de la API, clave privada, certificado) van cifradas, nunca se muestran ni aparecen en logs, y jamás se inventan ni se escriben en el código.
- **Fidelidad:** el JSON firmado y el sello se guardan sin modificar, y los cálculos deben cuadrar al centavo con los ejemplos oficiales.
- **Idioma:** la interfaz y los mensajes van en español. Los mensajes de error de Hacienda se traducen a lenguaje simple.

### 0.4 Contexto del negocio (para que entiendas el porqué)
- Escobar Net es un emprendimiento de software de El Salvador. Su equipo es joven y está aprendiendo.
- **Modelo elegido: SaaS.** Una sola plataforma en la nube para muchas empresas, porque:
  - es la única forma de competir con precios de mercado (desde unos $100 al año);
  - una actualización de Hacienda se aplica una sola vez para todos;
  - el equipo es pequeño.
  - Para empresas grandes se ofrecerá luego una **instalación dedicada** con el mismo código (`APP_MODE=dedicado`).
- **Competencia principal:** el Sistema de Facturación gratuito de Hacienda. Es manual, pide la clave privada en cada factura y no tiene inventario, caja, contingencia automática ni API. Nuestra plataforma automatiza todo eso.
- **Stack elegido:** Laravel con PostgreSQL, Redis, el firmador oficial en Docker y almacenamiento S3. El equipo eligió Laravel; PostgreSQL se eligió por sus decimales exactos (`NUMERIC`), `jsonb`, Row Level Security y bloqueos transaccionales para los correlativos.
- Ante Hacienda, cada empresa cliente es la emisora (su NIT, su certificado, sus credenciales). Escobar Net solo pone la herramienta. **Escobar Net nunca pide credenciales por chat ni WhatsApp**: el cliente las carga él mismo en un formulario seguro.

---

## PARTE 1 — CLAUDE.md (guardar como `CLAUDE.md`)

<<<INICIO CLAUDE.md>>>
## CLAUDE.md — Plataforma DTE de Escobar Net

Este archivo da las instrucciones permanentes del proyecto. Léelo completo antes de cada tarea.

### Qué es el proyecto

Es una plataforma **SaaS multiempresa** de facturación electrónica (DTE) para El Salvador. Muchas empresas usan el mismo sistema. Cada una emite sus documentos con su propio NIT, certificado y credenciales de la API de Hacienda (DGII). Ninguna empresa puede ver los datos de otra. El mismo código debe funcionar también en `APP_MODE=dedicado` (una sola empresa) sin cambios de código.

Archivos de referencia, en este orden de lectura:
1. `CLAUDE.md` (este): reglas que nunca se rompen.
2. `ESPECIFICACION.md`: qué construir, con todas las reglas de negocio y técnicas.
3. `PLAN.md`: en qué orden, con criterios de terminado y estado actual.

Las fuentes oficiales están en `docs/oficial/`. Si una regla de este archivo choca con ellas, manda este orden:

1. Normativa de Cumplimiento DTE v2.0
2. Manual Técnico del Sistema de Transmisión (MTIST) v2
3. Esquemas JSON oficiales (`resources/schemas/`)
4. Manual Funcional y catálogos

El curso interno (`docs/curso/`) explica el negocio. Úsalo para entender, no como especificación.

### Stack

- PHP 8.2 o superior, con la versión estable actual de Laravel (revisa `composer.json` antes de agregar paquetes).
- **PostgreSQL** como única base de datos. No uses MySQL ni SQLite en producción. Para las pruebas usa PostgreSQL en Docker, no SQLite: hacen falta RLS y `NUMERIC`.
- Redis para colas y caché. Laravel Horizon para supervisar las colas.
- El firmador oficial de Hacienda, `svfe-api-firmador` (Java), corre en Docker en la red interna, puerto 8113. Nunca se expone a internet.
- `opis/json-schema` para validar contra los esquemas oficiales.

### Reglas que NUNCA se rompen

#### Dinero y cálculos
- Nunca uses `float` ni `double` para montos, cantidades, precios ni impuestos. Usa siempre `App\Support\Decimal`.
- Ítems (cuerpo): 8 decimales con HALF_UP. Resumen: 2 decimales con HALF_UP. Se acepta una holgura de ±0.01.
- El resumen se calcula a partir de los valores de los ítems ya redondeados a 8 decimales.
- En PostgreSQL los montos van como `NUMERIC(20,8)` o `NUMERIC(18,2)`. Nunca como `real` ni `double precision`.
- En el JSON, los números se emiten exactos con `DteJson` (el marcador `__DEC__`), nunca con `json_encode` de floats.

#### Multiempresa (aislamiento)
- Toda tabla con datos de negocio lleva `empresa_id NOT NULL` con su índice.
- Toda consulta pasa por el scope global `EmpresaScope`. Si alguna vez se omite (`withoutGlobalScopes`), hay que justificarlo en un comentario.
- Además del scope, se activa **Row Level Security** de PostgreSQL. Cada petición y cada job hace `SET app.empresa_id = …` antes de consultar.
- Cada nueva tabla de negocio lleva una prueba que demuestre que la empresa A no puede leer ni escribir datos de la empresa B.
- Los jobs en cola reciben el `empresa_id` explícito y lo fijan antes de trabajar.

#### Secretos y credenciales
- La contraseña de la API de Hacienda, la contraseña de la llave privada y el certificado `.crt` **pertenecen al cliente**.
- Se guardan cifrados: `Crypt` de Laravel con una llave dedicada (`DTE_SECRETS_KEY`), separada de `APP_KEY`.
- Los certificados se guardan en un disco privado. El firmador los lee desde ahí, nombrados `{NIT}.crt`.
- Nunca se imprimen en logs, excepciones, respuestas de la API, el panel ni los tests. Usa `#[\SensitiveParameter]` y oculta esos campos en el modelo (`$hidden`).
- Nunca pidas ni pongas credenciales reales en el código, en los fixtures ni en el chat. Para los tests se usan valores falsos.
- El usuario y la contraseña de los servicios DGII (los del portal web) **no se guardan nunca** en la plataforma.

#### Identificadores y numeración
- `codigoGeneracion`: UUID v4 en MAYÚSCULAS, de 36 caracteres.
- `numeroControl`: `DTE-{tipo}-{establecimiento 4}{puntoVenta 4}-{correlativo 15}`, con un total de 31 caracteres.
- El correlativo se asigna dentro de una transacción con `SELECT … FOR UPDATE` y nunca puede repetirse.
- El correlativo se reinicia en 1 cada 1 de enero.
- El modo de correlativo se configura por empresa: `por_tipo` o `centralizado`. Ver la decisión pendiente en `PLAN.md`.
- Si un documento es rechazado y se reenvía, conserva el mismo `codigoGeneracion`.

#### Documentos
- Antes de firmar, todo JSON se valida contra el esquema oficial de su tipo y versión.
- El JSON firmado y el `selloRecibido` se guardan **sin modificar** (columna `jsonb` más el archivo original). Nunca se regeneran después de transmitidos.
- Los archivos se nombran `{codigoGeneracion}.json` y `{codigoGeneracion}.pdf`.
- El PDF del ambiente de pruebas (`ambiente = "00"`) lleva una marca visible: "SIN VALOR LEGAL — AMBIENTE DE PRUEBAS".
- El correo de entrega solo habla de la entrega: nada de publicidad.

#### API de Hacienda
- Timeout de 8 s. Si se vence, consultar el estado; se permiten máximo 2 reenvíos y después se pasa a contingencia.
- El token se guarda en caché por empresa: 24 h en producción y 48 h en pruebas. Se renueva antes de vencer.
- Si el login falla, no se reintenta en bucle: 3 intentos fallidos bloquean al usuario del cliente.
- En contingencia se reintenta cada 15 minutos con el scheduler.
- Las URL salen de `config/dte.php`. Nunca se escriben a mano en el código.

### Cómo trabajar

- Haz una tarea a la vez, según `PLAN.md`. No adelantes pasos.
- Cada cambio va con pruebas (Pest o PHPUnit). Un paso está terminado solo cuando pasan sus pruebas de "Terminado cuando".
- Cálculos: cada builder lleva pruebas con los ejemplos numéricos de los manuales oficiales, que deben cuadrar al centavo.
- Antes de terminar, ejecuta `php artisan test` y el linter (`./vendor/bin/pint`).
- Si una regla oficial es ambigua, no inventes: deja un `// TODO(normativa): …` y avisa en tu respuesta.
- Los mensajes que ve el usuario final van en español.

### Comandos

```bash
docker compose up -d          # postgres, redis, firmador
php artisan migrate --seed    # incluye los catálogos oficiales
php artisan test
./vendor/bin/pint
php artisan horizon
```

<<<FIN CLAUDE.md>>>

---

## PARTE 2 — ESPECIFICACION.md (guardar como `ESPECIFICACION.md`)

<<<INICIO ESPECIFICACION.md>>>
## ESPECIFICACION.md — Plataforma SaaS de Facturación Electrónica (DTE) · Escobar Net

> Esta es la especificación funcional y técnica completa. Se armó a partir de:
> la Normativa de Cumplimiento DTE v2.0, el Manual Técnico del Sistema de Transmisión (MTIST) v2, el Manual Funcional, los Catálogos, el Manual de Usuario del Sistema de Facturación v2.1, el Manual del Sitio de Emisores DTE v1.4 y el curso interno de Escobar Net.
> Los originales están en `docs/oficial/`. Si este archivo y un original no coinciden, **manda el original**. Deja `TODO(normativa)` y avisa.
> Las líneas marcadas con **[VERIFICAR]** son datos que deben confirmarse contra el esquema JSON oficial o el MTIST antes de programarlos.

---

### 1. El producto

#### 1.1 Qué vendemos
Una plataforma web **multiempresa (SaaS)**. Cualquier contribuyente salvadoreño la usa para emitir, firmar, transmitir, entregar, consultar e invalidar sus Documentos Tributarios Electrónicos (DTE) ante el Ministerio de Hacienda (DGII).

- Cada empresa (tenant) factura con **su propio NIT, su certificado y sus credenciales de la API**. Ante Hacienda, la emisora es la empresa. Escobar Net solo pone la herramienta.
- Una sola instalación sirve a todas las empresas. El mismo código debe poder instalarse también en **modo dedicado** (una sola empresa) para clientes grandes. Esto se controla con `APP_MODE=saas|dedicado` y no debe exigir cambios de código.
- La plataforma usa el **Sistema de Transmisión** de Hacienda, es decir, la API. No usa el Sistema de Facturación gratuito, que es nuestra competencia.

#### 1.2 Contra quién competimos y por qué nos eligen
El portal gratuito de Hacienda obliga a llenar cada factura a mano, pide la clave privada en cada documento y no tiene inventario, caja ni contingencia automática. Está pensado para pocos documentos. Nuestra plataforma resuelve eso:

- Catálogo de productos y clientes, con precios e impuestos automáticos.
- Firma en el servidor: el cajero nunca ve claves.
- Contingencia y reenvío automáticos.
- Entrega automática por correo y enlace (y WhatsApp en una fase posterior).
- Envío por lotes y una API para conectar el punto de venta (POS) o el ERP del cliente.
- Reportes y libros de IVA listos para el contador.

#### 1.3 Actores y roles
| Rol | Ámbito | Puede |
|---|---|---|
| `superadmin` | Escobar Net | Gestionar empresas, planes, suscripciones, soporte, métricas globales. **No ve** credenciales de clientes |
| `dueno` | Empresa | Todo dentro de su empresa, incluidas credenciales y usuarios |
| `contador` | Empresa | Emitir, invalidar, reportes, libros, catálogos |
| `cajero` | Empresa y punto de venta | Emitir FE/CCF desde su punto de venta y consultar lo que él emitió |
| `consulta` | Empresa | Solo lectura |
| Token API | Empresa | Emitir y consultar por REST (integración POS/ERP) |

Un usuario puede pertenecer a varias empresas (por ejemplo, un contador externo) y cambiar de empresa activa.

---

### 2. Glosario mínimo
- **DTE**: Documento Tributario Electrónico, en formato JSON y firmado.
- **Evento**: mensaje firmado que afecta a un DTE (invalidación, contingencia, retorno, operaciones especiales).
- **Sello de recepción** (`selloRecibido`): código de 40 caracteres alfanuméricos en mayúsculas que Hacienda otorga cuando acepta el documento. Sin sello, el documento no es válido.
- **Código de generación**: UUID v4 en MAYÚSCULAS. Identifica de forma única al DTE o evento.
- **Número de control**: `DTE-TT-EEEEPPPP-NNNNNNNNNNNNNNN`.
- **Ambiente**: `00` pruebas, `01` producción (CAT-001). Los documentos de pruebas no tienen valor legal.
- **Representación gráfica**: el PDF legible que se entrega junto al JSON.
- **NIT**: 14 o 9 dígitos. **NRC**: registro de IVA. **DUI**: 9 dígitos.

---

### 3. Tipos de documento (CAT-002)

| Código | Documento | Sigla | Versión JSON* | Quién lo usa / para qué |
|---|---|---|---|---|
| 01 | Factura Electrónica | FE | 2 | Venta a consumidor final; el precio **incluye** IVA |
| 03 | Comprobante de Crédito Fiscal | CCF | 4 | Venta a contribuyente con NRC; precio **sin** IVA y el IVA desglosado |
| 04 | Nota de Remisión | NR | 4 | Traslado de bienes sin venta (consignación, traslado entre bodegas, etc.) |
| 05 | Nota de Crédito | NC | 4 | Disminuye un CCF (devolución, descuento posterior) |
| 06 | Nota de Débito | ND | 4 | Aumenta un CCF |
| 07 | Comprobante de Retención | CR | 2 | Agente de retención retiene IVA al proveedor |
| 08 | Comprobante de Liquidación | CL | 2 | Mandatario liquida ventas por cuenta de terceros |
| 09 | Documento Contable de Liquidación | DCL | 2 | Agente perceptor liquida (tarjetas, etc.) |
| 11 | Factura de Exportación | FEX | 2 | Exportación de bienes o servicios (tasa 0%) |
| 14 | Factura de Sujeto Excluido | FSE | 2 | **Compra** a persona no inscrita en IVA; el emisor es el comprador; se retiene renta |
| 15 | Comprobante de Donación | CD | 2 | Donatario documenta una donación recibida |

\* Versiones según la Normativa v2.0, Anexo II, campo `identificacion.version`: «2 para FE, CRE, CLE, DCLE, FEXE, FSEE y CDE; 4 para CCFE, NRE, NCE y NDE». **[VERIFICAR]** cada versión contra el nombre y contenido del esquema JSON oficial vigente de cada tipo. El curso registró FEX v3; si el esquema dice otra cosa, avisar.

**Eventos**
| Evento | Para qué | Versión* |
|---|---|---|
| Invalidación | Anular un DTE con sello dentro del plazo | 3 [VERIFICAR] |
| Contingencia | Avisar que no se pudo transmitir por fuerza mayor | 4 [VERIFICAR] |
| Retorno | Devoluciones y reembolsos en FE, FEX y FSE (nuevo en v2.0) | [VERIFICAR] |
| Operaciones Especiales | Facturas de Venta Simplificada y comprobantes de control interno autorizados (nuevo en v2.0) | [VERIFICAR] |

Siglas en la Normativa: CCFE, NCE, NDE, NRE, CRE, CLE, DCLE, FCE (factura), FEXE, FSEE, CDE.

---

### 4. Estructura general de un DTE (Anexo II)

Secciones posibles. Cada tipo usa un subconjunto, según su esquema:

1. `identificacion`
   - `version`, `ambiente`, `tipoDte`, `numeroControl`, `codigoGeneracion`.
   - `tipoModelo`: 1 previo, 2 diferido.
   - `tipoOperacion`: 1 normal, 2 contingencia.
   - `tipoContingencia` y `motivoContin`, que son null si la operación es normal.
   - `fecEmi` (AAAA-MM-DD), `horEmi` (HH:MM:SS) y `tipoMoneda` ("USD").
2. `documentoRelacionado`: máximo 50 documentos; cada uno con tipo, tipo de generación (1 físico / 2 electrónico), número y fecha.
3. `emisor`: NIT, NRC, nombre, código y descripción de la actividad, nombre comercial, tipo de establecimiento, dirección, teléfono, correo, `codEstableMH`, `codEstable`, `codPuntoVentaMH` y `codPuntoVenta`.
4. `receptor`: el contenido varía por tipo. En FE puede ir todo null (ver §6.3). En CCF va completo.
5. `otrosDocumentos`: documentos asociados (emisor, receptor, médico, transporte).
6. `ventaTercero`: venta por cuenta de terceros (NIT y nombre).
7. `compraTercero`: compra por cuenta de terceros (nuevo en v2.0). [VERIFICAR] en qué esquemas existe.
8. `cuerpoDocumento`: ítems; máximo 2000 líneas.
9. `resumen`.
10. `extension`: datos de quien entrega y quien recibe, observaciones, placa del vehículo.
11. `apendice`: campos libres `{campo, etiqueta, valor}`.

El **Archivo DTE** que se entrega al receptor es el JSON de la estructura anterior más:
```json
"firmaElectronica": "<JWS compacto>",
"selloRecibido": "<40 caracteres>"
```

Condiciones de cada campo (regla 1.5 del Anexo I):
- **Requerido para su transmisión**: si falta, Hacienda rechaza.
- **Requerido por tipo de operación**: obligatorio solo cuando la ley o el giro lo exigen.
- **Opcional**.

Columna «Representación Gráfica» (regla 8 del Anexo I):
- **A**: se muestra siempre.
- **B**: se muestra cuando la ley lo exige.
- **C**: no es obligatorio mostrarlo.
- **D**: se muestra con un guion.

**Tarea:** extraer de los Anexos II y III de la Normativa una tabla `config/dte/campos.php` con, por tipo y por campo, su condición y su letra A/B/C/D. Esa tabla alimenta tanto las validaciones como el generador de PDF.

---

### 5. Identificadores

#### 5.1 Código de generación
- UUID v4 aleatorio (nunca secuencial), en MAYÚSCULAS, de 36 caracteres. Ejemplo: `287BF64E-0246-4E1F-BC08-7B83C598DB93`.
- Si un documento es rechazado y se reenvía corregido, se usa **el mismo código**, salvo que el rechazo sea por el código.
- Los archivos se nombran con el código: `{codigo}.json` y `{codigo}.pdf`.

#### 5.2 Número de control
- Formato `DTE-{TT}-{EEEE}{PPPP}-{15 dígitos}`, 31 caracteres. Ejemplo: `DTE-01-M001P001-000000000000039`.
- `EEEE` es el código del establecimiento (casa matriz, sucursal, bodega o patio), con 4 caracteres alfanuméricos. `PPPP` es el punto de venta, también con 4. Se usa la codificación que asigna Hacienda.
- El correlativo empieza en 1 y se reinicia el 1 de enero de cada año.
- Modo de correlativo: la Normativa (7.1.2) dice que puede ser **centralizado** o **por establecimiento**, y no por punto de venta ni por tipo sin resolución. En el mercado se usa por tipo. → Implementa los modos `por_tipo` (por defecto), `por_establecimiento` y `centralizado`, configurables por empresa. Ver la decisión pendiente en PLAN.md.
- Se asigna con `SELECT … FOR UPDATE` dentro de la transacción. Nunca se repite. Un número consumido por un documento que después falla en validación local no se reutiliza: se registra como anulado internamente.

---

### 6. Reglas de cálculo

#### 6.1 Precisión
- Cuerpo (ítems): hasta **8 decimales**; HALF_UP (si el 9.º decimal es ≥ 5, sube el 8.º).
- Resumen: **2 decimales**; HALF_UP.
- Holgura permitida: **±0.01** entre lo calculado y lo informado.
- El resumen se calcula a partir de los valores de los ítems (8 decimales), **no** con la precisión interna del sistema.
- Implementación: `App\Support\Decimal`, aritmética exacta sobre strings. Prohibido usar `float`.

#### 6.2 Ítem (`cuerpoDocumento[]`), campos típicos
- `numItem`, `tipoItem` (CAT-011: 1 bien, 2 servicio, 3 ambos, 4 otros tributos).
- `numeroDocumento` (si relaciona un documento), `codigo`, `codTributo`, `descripcion`, `cantidad`, `uniMedida` (CAT-014), `precioUni`, `montoDescu`.
- `ventaNoSuj`, `ventaExenta`, `ventaGravada`: un ítem va en una sola columna según su tipo de venta.
- `tributos` (CAT-015), `psv` (precio sugerido de venta), `noGravado` y `ivaItem` (solo en FE).
- Fórmula base: `ventaX = cantidad × precioUni − montoDescu` (8 decimales).

#### 6.3 Factura Electrónica (01)
- El precio **incluye IVA**.
- `ivaItem = ventaGravada × 13 / 113`, a 8 decimales.
- `resumen.totalIva` se calcula desde el subtotal gravado a 2 decimales (`gravada × 13/113`), dentro de la holgura respecto a la suma de los `ivaItem`.
- `tributos` del ítem: null. El IVA va incluido.
- Receptor:
  - Si la operación es **menor a $25,000.00**, todos los campos del receptor son opcionales y está **prohibido exigirle datos al cliente**. La venta nunca se niega por eso.
  - Si es **igual o mayor a $25,000.00**, son obligatorios el nombre, el tipo de documento y el número de documento.
- Retención de IVA (`ivaRete1`) solo si el receptor es agente de retención. Percepción: no aplica en FE.

#### 6.4 Crédito Fiscal (03)
- El precio **no incluye IVA**.
- `tributos` del ítem gravado: `["20"]` (IVA 13%).
- `resumen.tributos = [{codigo:"20", descripcion:"Impuesto al Valor Agregado 13%", valor: totalGravada×0.13}]`.
- `ivaPerci1`: percepción del 1% cuando el emisor es agente de percepción y el receptor aplica. `ivaRete1`: retención del 1% cuando el receptor es gran contribuyente o agente de retención. [VERIFICAR] umbrales y reglas en el Manual Funcional.
- El receptor va completo: NIT, NRC, nombre, actividad, dirección (departamento, municipio, distrito y complemento) y correo.
- **Ejemplos oficiales que deben cuadrar** (los tests los incluyen):
  - 25 × $250.00, descuento de $20.00 → subtotal $6,230.00, IVA $809.90, **total $7,039.90**.
  - 100 × $25.00, descuento global de $200.00 → $2,300.00, IVA $299.00, **total $2,599.00**.
  - Ejemplo del Manual Funcional: **total $761.20**.
  - Ítem «Otros tipos de impuesto» de $95.00 → IVA $12.35 → total $107.35.

#### 6.5 Resumen (campos comunes; varían por tipo)
- `totalNoSuj`, `totalExenta`, `totalGravada`.
- `subTotalVentas = noSuj + exenta + gravada`.
- `descuNoSuj`, `descuExenta`, `descuGravada`: descuentos globales por tipo de venta.
- `porcentajeDescuento`, `totalDescu` (descuentos de ítems más globales).
- `tributos`, `subTotal`, `ivaPerci1`, `ivaRete1`, `reteRenta`.
- `montoTotalOperacion`, `totalNoGravado`.
- `totalPagar`, `totalLetras` (por ejemplo, «SETECIENTOS SEIS DÓLARES CON VEINTICINCO CENTAVOS»), `totalIva` (FE) y `saldoFavor`.
- `condicionOperacion`: 1 contado, 2 crédito, 3 otro.
- `pagos[]`, con `codigo` (CAT-017), `montoPago`, `referencia`, `plazo` (CAT-018) y `periodo`. **La suma de los pagos debe ser igual a `totalPagar`.**
- `numPagoElectronico`.
- Ojo: el Manual Funcional trae un ejemplo cuyo total de descuentos suma $112.50 aunque el texto dice $113.50. Confiar en el cálculo, no en ese texto.

#### 6.6 Otros tipos de ítem (Manual del Sistema de Facturación)
- **Monto No Afecto**: cargos fuera de la base imponible (por ejemplo, propina). Va a `noGravado` o `totalNoGravado`. No aplica en NR, FSE ni CD.
- **Otros tipos de impuesto**: tributos del CAT-015, sección 2 (A8, 57, 90, D4, D5, A6, …). No aplica en FSE ni CD.

#### 6.7 Particularidades por tipo
- **NC / ND**: `documentoRelacionado` es obligatorio y cada ítem referencia el CCF que ajusta (`numeroDocumento`). Solo aplican sobre CCF (y CR en ciertos casos). La condición de la operación va sin pagos.
- **NR**: requiere `receptor.bienTitulo` («Título de»: CAT-025). Puede no tener valor de venta.
- **FEX**:
  - `tipoItemExpor` (1 bienes, 2 servicios, 3 ambos), `recintoFiscal` (CAT-027) y `regimen` (CAT-028).
  - Receptor con país (CAT-020) y tipo de persona.
  - `incoterms` (CAT-031), `seguro`, `flete` y `otrosDocumentos` (incluido el transporte: placas, modo, conductor).
  - IVA al 0%.
- **FSE**:
  - El emisor es el **comprador** y el «sujeto excluido» es el vendedor.
  - Hay `compra` en lugar de venta.
  - `reteRenta` del 10% sobre servicios. [VERIFICAR] porcentaje y aplicabilidad.
  - No usa IVA.
- **CR**: el emisor es el agente de retención y el receptor el sujeto retenido. Los ítems referencian los documentos retenidos (`tipoDte`, `numDocumento`, `montoSujetoGrav`, `codigoRetencionMH`, `ivaRetenido`).
- **CL / DCL**: liquidaciones. El cuerpo lista documentos, no productos. DCL usa la sección `extension`.
- **CD**: el donatario emite. Ítems con `tipoDonacion` (efectivo, bien o servicio), `valorUni`, `depreciacion` y `valorDonado = valorUni × cantidad − depreciación`. `otrosDocumentos` es obligatorio. Pagos como forma de donación (solo cuenta el efectivo).

---

### 7. Firma electrónica
- Estándar: **JWS** con algoritmo **RS512**, usando el certificado que Hacienda entrega a cada contribuyente (CAdES, PKCS8EncodedKeySpec).
- Se usa el firmador oficial `svfe-api-firmador` (Java) en Docker, en la red interna y en el puerto 8113.
  - `POST http://firmador:8113/firmardocumento/`
  - Body: `{"nit":"<NIT>","activo":true,"passwordPri":"<clave privada>","dteJson":{…}}`
  - Respuesta con `status: "OK"` y en `body` el JWS compacto. Si `status: "ERROR"`, se registra sin exponer la clave.
- Certificados: archivos `{NIT}.crt` en un volumen privado que monta el firmador (`/uploads`). Cada empresa sube el suyo desde el panel. Se guarda con permisos 600 y nunca se sirve por HTTP.
- La clave privada (`passwordPri`) va cifrada en la base de datos y solo se descifra en memoria dentro del job que firma.

---

### 8. API de Hacienda (Sistema de Transmisión, MTIST v2)

#### 8.1 URLs (en `config/dte.php`)
| | Pruebas | Producción |
|---|---|---|
| Base | `https://apitest.dtes.mh.gob.sv` | `https://api.dtes.mh.gob.sv` |
| Consulta pública (QR) | `https://admin.factura.gob.sv/consultaPublica?ambiente={00\|01}&codGen={codigo}&fechaEmi={AAAA-MM-DD}` | ídem |

#### 8.2 Servicios
| Servicio | Método y ruta | Notas |
|---|---|---|
| Autenticación | `POST /seguridad/auth`, `application/x-www-form-urlencoded`, `user=<NIT>&pwd=<clave API>` | Devuelve el token (ya incluye el prefijo `Bearer`, [VERIFICAR]). Vigencia: **24 h en producción, 48 h en pruebas** |
| Recepción de un DTE | `POST /fesv/recepciondte` | Body: `{ambiente, idEnvio, version, tipoDte, documento: <JWS>, codigoGeneracion}` [VERIFICAR] nombres |
| Recepción por lote | `POST /fesv/recepcionlote` | `{ambiente, idEnvio: UUID, version, nitEmisor, documentos: [JWS…]}`. Hasta **100 DTE por lote** (y hasta 400 lotes) |
| Consulta de un DTE | `POST /fesv/recepcion/consultadte` | `{nitEmisor, tdte, codigoGeneracion}` |
| Consulta de un lote | `GET /fesv/recepcion/consultadtelote/{codigoLote}` | Estado de cada documento del lote |
| Contingencia | `POST /fesv/contingencia` | `{nit, documento: <JWS del evento>}` |
| Invalidación | `POST /fesv/anulardte` | `{ambiente, idEnvio, version, documento: <JWS del evento>}` |

- Encabezados: `Authorization: <token>`, `Content-Type: application/json` y un `User-Agent` propio, por ejemplo `EscobarNet-DTE/1.0`. [VERIFICAR] si el MTIST exige un User-Agent concreto.
- Respuesta de recepción, campos relevantes: `estado` (`PROCESADO` o `RECHAZADO`), `selloRecibido`, `fhProcesamiento`, `clasificaMsg`, `codigoMsg`, `descripcionMsg` y `observaciones[]`. **Guardar la respuesta completa.**
- Credenciales: **3 intentos de login fallidos bloquean al usuario de la API del cliente.** Nunca reintentar el login en bucle. Al tercer error, marcar la empresa como `credenciales_invalidas` y avisarle.
- Contraseña de la API: el MTIST dice de 13 a 25 caracteres. El Sitio de Emisores v1.4 pide de 8 a 30, con mayúscula y carácter especial, sin secuencias ni espacios. En el onboarding, recomendar una que cumpla ambas reglas. **La crea el cliente en el portal de Hacienda; nosotros solo la recibimos cifrada.**

#### 8.3 Política de reintentos (MTIST)
1. Enviar con un timeout de **8 segundos**.
2. Si hay timeout o error de red, **consultar** con `consultadte` si llegó.
3. Si no llegó, reenviar. **Máximo 2 reenvíos.**
4. Si sigue fallando, pasar a **contingencia** (§10).

#### 8.4 Fechas
- `fecEmi` y `horEmi` no pueden diferir de la fecha de recepción más allá de la tolerancia: **1 día**, o **30 minutos** si es el último día del período tributario. [VERIFICAR] redacción exacta en el MTIST.
- Zona horaria: `America/El_Salvador`. Siempre se guarda con la hora local de emisión.

#### 8.5 Flujo de validación en Hacienda (Anexo I, regla 7)
Hacienda valida en este orden: **token → firma → esquema → reglas de negocio** → genera el sello. Cada paso puede devolver un error. Hay que mapear los `codigoMsg` a mensajes en español entendibles para el cliente (tabla `config/dte/mensajes_mh.php`, que se completa conforme aparezcan en las pruebas).

---

### 9. Modalidades de emisión y plazos (Normativa v2.0, regla 9)

| # | Modalidad | Transmisión | Documentos |
|---|---|---|---|
| 1 | Normal previa (por defecto) | Antes de entregar al receptor | Todos |
| 2 | Normal diferida (solo con **resolución** de Hacienda y solo por lote) | Hasta 1 día después de generar y entregar | CCF, NC, ND, NR, CR, FE, FEX, FSE |
| 3 | Contingencia | Hasta **72 h** después del sello del Evento de Contingencia | Los mismos de la fila 2 |

Eventos en contingencia: la **invalidación** tiene 24 h desde que cesa la falla; el **retorno**, 72 h desde el sello del evento de contingencia.

Un DTE está **emitido** solo si fue generado, firmado, transmitido, tiene sello **y** fue entregado.

Orden lógico: lo que afecta a otro documento (NC, ND, invalidación, retorno) se transmite **después** del documento afectado. El documento afectado debe tener sello.

**Rechazos:** se corrigen y retransmiten dentro de **24 horas** desde que Hacienda comunica el motivo, con el mismo código de generación.

---

### 10. Contingencia (regla 13.2)
- Solo procede por caso fortuito o fuerza mayor: caída de internet o indisponibilidad de Hacienda (CAT-005, tipo de contingencia).
- Antes de declararla, agotar la política de reintentos (§8.3).
- Mientras dura:
  1. Se generan y firman los documentos con `tipoModelo = 2` y `tipoOperacion = 2`, más `tipoContingencia` y `motivoContin`.
  2. Se **entregan** al cliente en estado transitorio, con el PDF indicando la contingencia.
  3. Se reintenta la conexión **al menos cada 15 minutos**.
- Cuando vuelve el servicio:
  1. Se envía el **Evento de Contingencia** dentro de **24 h** desde el cese de la falla. Incluye el detalle de los documentos afectados, con el inicio y fin de la contingencia.
  2. Con el sello del evento, se transmiten los documentos (por lote) dentro de **72 h**.
- Si la contingencia dura **más de 3 días consecutivos**, la empresa debe presentar un **Informe Técnico de Contingencia**. El sistema alerta al dueño y le genera un borrador con los datos (fechas, causa, documentos).

---

### 11. Estados de un documento (regla 10.1)

```
borrador → validado → firmado → enviado ─┬→ transmitido (con sello) ─┬→ ajustado (NC/ND/retorno lo afectó)
                                         │                            ├→ observado (sello con observaciones)
                                         │                            └→ invalidado
                                         ├→ rechazado → (corregir ≤24h, mismo código) → enviado
                                         └→ transitorio (contingencia/diferido) → enviado
```
- Enum `EstadoDte`: `borrador`, `validado`, `firmado`, `enviado`, `transitorio`, `transmitido`, `ajustado`, `observado`, `rechazado`, `invalidado`.
- Cada transición queda en la tabla `dte_eventos_internos`, con quién, cuándo, payload y respuesta.
- Un documento **rechazado** presume ingresos gravados ante Hacienda: el panel lo muestra en rojo hasta resolverlo.

---

### 12. Invalidación (regla 13.1, Cuadro 6)

| Documento | Cuándo procede | Plazo para transmitir el evento |
|---|---|---|
| CCF, NR, NC, ND, CL, CR, DCL, CD | Error del emisor que no implica ajuste, o rescisión total | Hasta el **10.º día hábil del mes siguiente** al período en que obtuvo el sello |
| FE, FEX, FSE | Rescisión total o modificación de la operación | **3 meses** desde el sello |
| FE, FEX de **medicamentos perecederos** | Rescisión total | **2 años** desde el sello |
| Evento de Retorno y de Operaciones Especiales | Errores en su contenido | 10.º día hábil del mes siguiente |

- **Fecha del evento**: desde el mismo día de generación del documento hasta el límite. En FE, FEX y FSE se aplica la ventana de 3 meses tanto a la fecha del evento como a la fecha de transmisión.
- **Tipo de invalidación** (CAT-024): 1 = error en la información (requiere un documento que reemplace), 2 = rescindir la operación, 3 = otro (requiere un documento que reemplace).
- Si el motivo no es rescisión total (salvo en NC y CL), **primero** se emite el documento nuevo y el evento referencia ambos.
- **Error frente a afectación:** un error (dato mal escrito) se invalida. Una afectación (cambia la cantidad o el precio pactado) en CCF o CR se corrige con **NC o ND**, no invalidando.
- Solo se invalida lo que tiene sello.
- En FE por debajo de $25,000 sin nombre de receptor, no se exige el nombre del receptor. Siempre se exigen el nombre y el documento del **responsable** (emisor) y del **solicitante**.
- Datos del evento: código de generación del original, número de control, sello, fecha, tipo, tipo de invalidación, documento que reemplaza, motivo, responsable (nombre, tipo y número de documento), solicitante (nombre, tipo y número de documento), correo y teléfono.
- El sistema bloquea la acción fuera de plazo con el mensaje «FECHA FUERA DE PLAZO PERMITIDO».
- Las invalidaciones se declaran en el período de su **fecha del evento**.

---

### 13. Evento de Retorno (13.3) y Operaciones Especiales (13.4)
- **Retorno**, para FE, FEX y FSE con sello:
  - Casos: devolución de bienes (por el receptor o un tercero), reembolsos por defectos ocultos, recuperación de depósitos de envases, reimportación de exportaciones, disminución del valor exportado por pérdida o daño.
  - Plazo: **3 meses** desde el sello del documento original.
  - No es descuento, no puede superar lo vendido y no genera crédito fiscal.
  - **No invalida** el original.
  - Cada evento se relaciona con un solo tipo de documento.
  - Se entrega al receptor con su Archivo y PDF. Se puede invalidar. No se puede ajustar.
- **Operaciones Especiales**:
  - Para empresas autorizadas a usar Facturas de Venta Simplificada (FVS) o comprobantes de control interno por resolución.
  - Plazo: hasta el **10.º día hábil del mes siguiente**.
  - Detalle por rangos (tipo de documento, fecha de origen, cantidad, del-al, descripción, precio y subtotal) con totales no sujetos, exentos, gravados e IVA.
- **Coexistencia**: desde el 1 de enero de 2025, quien emite DTE ya no puede usar tiquetes. El consumidor final recibe Factura Electrónica.

---

### 14. Entrega al receptor (regla 11)
- Se entregan **siempre** el Archivo DTE (`{codigo}.json`, idéntico byte a byte a lo transmitido, más la firma y el sello) **y** la representación gráfica (`{codigo}.pdf`).
- El medio se acuerda con el cliente: correo, mensajería, enlace de descarga, QR. Sin acuerdo, cualquier medio que deje rastro.
- **Correo:** solo información de la entrega. **Prohibido** incluir publicidad, promociones o encuestas.
- **Portal de descarga** (lo ofrecemos):
  - Debe mostrar como mínimo el nombre y NIT del emisor, el modelo y tipo de transmisión, la fecha y hora, el código de generación, el sello, un QR y una instrucción breve.
  - Debe estar disponible siempre.
  - Si la empresa cierra o se liquida: **60 días hábiles** más de disponibilidad.
  - Enlaces firmados y no adivinables.
- Impreso, solo si el receptor no tiene ningún medio electrónico.
- **La entrega no se retiene por falta de pago.**
- Eventos de invalidación y retorno: también se entregan al receptor.

### 15. Representación gráfica (PDF)
- Un **diseño único y uniforme por tipo de documento** para cada empresa (se permite su logo).
- Muestra los campos con letra A, los B cuando aplican y los D con guion, **conservando los nombres de campo de la Normativa**.
- Incluye el QR con la URL de consulta pública (§8.1), el código de generación, el número de control, el sello y la fecha y hora de procesamiento.
- Si es contingencia, lo indica. Si es ambiente `00`: marca de agua «SIN VALOR LEGAL — AMBIENTE DE PRUEBAS».
- Si el error está solo en el PDF, se regenera el PDF. Si está en el DTE, se **invalida**. Nunca se edita el PDF para ocultar un error del DTE.
- Formatos: carta y ticket de 80 mm, para el punto de venta.

### 16. Conservación, seguridad y datos personales (reglas 12, Anexo I §5–6)
- Conservar JSON, firma, sello y PDF **inalterados**, en orden cronológico por período, durante el plazo del Código Tributario (diseñar para **10 años**). Hacienda **no** sirve de respaldo.
- Almacenamiento: los objetos van a S3 compatible, con versionado y bloqueo (object lock), cifrados en reposo. La base de datos guarda el `jsonb` más el hash SHA-256 del archivo.
- Módulos obligatorios: **Generación, Firmado, Transmisión y Entrega**.
- Arquitectura: alta disponibilidad, recuperación ante desastres, respaldos diarios con prueba de restauración, monitoreo, trazabilidad y reportes.
- Seguridad:
  - Protección contra ataques (WAF, límite de peticiones).
  - Control de accesos y 2FA obligatoria para dueños y superadmin.
  - Registro de accesos y bitácora inmutable.
  - Cifrado de datos sensibles, plan de continuidad y gestión de incidentes.
- Datos personales: la empresa responde por las fugas. Minimizar los datos, no usarlos para otros fines y enmascarar DUI y teléfonos en los listados.
- Hosting: en un país con tratados de extradición y de cooperación en ciberdelitos con El Salvador.
- Las credenciales de la API y las claves de firma son **exclusivas del contribuyente**. Escobar Net no las pide por chat ni WhatsApp. El cliente las carga él mismo en un formulario seguro.

---

### 17. Catálogos
- Fuente: `docs/oficial/Catalogos.xlsx` (y el PDF).
- CAT-001 a CAT-033, sembrados en las tablas `cat_*` con `codigo`, `valor`, `vigente` y `version`. Los más usados:
  - 001 ambiente · 002 tipo de documento · 003 modelo · 004 tipo de transmisión · 005 tipo de contingencia.
  - 009 tipo de establecimiento · 011 tipo de ítem · 012 departamento · 013 municipio (y distrito, según la versión vigente) · 014 unidad de medida.
  - 015 tributos · 016 condición de la operación · 017 forma de pago · 018 plazo · 019 actividad económica · 020 país.
  - 022 tipo de documento de identificación · 024 tipo de invalidación · 025 título de bienes remitidos.
  - 027 recinto fiscal · 028 régimen · 029 tipo de persona · 031 INCOTERMS · 032 domicilio fiscal.
- [VERIFICAR] con el Excel la numeración exacta y si la división territorial vigente es departamento, municipio y distrito (el portal ya muestra «Distrito»).
- El seeder es idempotente. Un comando `dte:catalogos:actualizar` compara contra un Excel nuevo y muestra las diferencias.

---

### 18. Proceso para que una empresa sea emisora (onboarding)

Lo hace el **cliente** en el portal de Hacienda. Nuestra plataforma lo guía paso a paso con un asistente:

1. El cliente solicita el ingreso al **ambiente de pruebas** en factura.gob.sv («Solicitud de Ingreso»).
2. Genera su **certificado de pruebas** y su **usuario y contraseña de la API de pruebas** (Sitio de Emisores → Gestión de Usuario API).
3. Los carga en nuestra plataforma: sube el `.crt` y escribe las contraseñas en un formulario cifrado.
4. Nuestro sistema ejecuta las **pruebas mínimas de la API** automáticamente, con barra de avance:

| DTE | Pruebas |
|---|---|
| FE | 90 |
| CCF | 75 |
| NR | 50 |
| NC | 50 |
| ND | 25 |
| CR | 50 |
| CL | 75 |
| DCL | 50 |
| FEX | 90 |
| FSE | 25 |
| CD | 25 |
| Eventos | 5 de cada uno |

   Solo se ejecutan los tipos que la empresa va a usar. Las cantidades se configuran en `config/dte/pruebas_minimas.php`.
5. El cliente ve en el portal de Hacienda (pantalla Inicio, con barras de avance) que completó las pruebas y **solicita la autorización**.
6. Una vez autorizado, genera el **certificado de producción** y el **usuario de la API de producción**, y los carga.
7. La empresa pasa a `ambiente = 01`.

Datos que se registran de la empresa: NIT, NRC, razón social, nombre comercial, actividades económicas (CAT-019), tipo de contribuyente (grande, mediano u otro), si es agente de retención o percepción, establecimientos con sus códigos MH y sus puntos de venta, dirección, teléfono, correo y logo.

---

### 19. Módulos de la plataforma (panel web)

#### 19.1 Para la empresa
1. **Inicio / tablero**: ventas del día y del mes, documentos por estado, alertas (rechazos, contingencia, credenciales vencidas o por vencer, plazos de invalidación por vencer).
2. **Emitir**: formulario guiado por tipo, con las 9 secciones del portal de Hacienda pero automatizadas:
   - identificación;
   - emisor (precargado) y receptor (búsqueda en el catálogo de clientes);
   - documentos relacionados y asociados, venta y compra por cuenta de terceros;
   - otros datos (FEX);
   - detalle (catálogo de productos, con tres tipos de ítem: producto o servicio, monto no afecto, otros impuestos);
   - resumen en vivo;
   - descuentos globales;
   - observaciones;
   - condición y pagos.
   Vista previa del JSON y del PDF antes de emitir.
3. **Caja rápida (POS)**: pantalla simplificada para FE y CCF, con escaneo de código, ticket de 80 mm y trabajo sin conexión con contingencia.
4. **Documentos emitidos**:
   - Filtros por fecha, tipo, estado, receptor y código.
   - Columnas como las del portal de Hacienda: tipo y número de documento del receptor, nombre, tipo, fecha de generación, fecha de recepción, monto, IVA y estado.
   - Acciones: ver PDF, descargar JSON, reenviar, invalidar, emitir NC o ND, registrar retorno.
   - Exportar a Excel.
5. **Documentos recibidos**: importar los DTE que reciben de sus proveedores (subiendo JSON o por un buzón de correo de entrada), validar su sello en la consulta pública y usarlos en el libro de compras.
6. **Invalidaciones**: listado con plazo restante y asistente.
7. **Contingencia**: estado actual, documentos pendientes, evento, borrador del informe técnico.
8. **Catálogos de la empresa**: productos y servicios (código, que debe coincidir con el F-983 si aplica; unidad; precio; tipo de venta; tributos), clientes, establecimientos y puntos de venta.
9. **Reportes**:
   - Libro de ventas a consumidor final.
   - Libro de ventas a contribuyentes.
   - Libro de compras.
   - Retenciones y percepciones.
   - Resumen para la declaración de IVA (F-07).
   - Todo exportable a Excel y CSV.
   - [VERIFICAR] los formatos de anexos que exige Hacienda.
10. **Usuarios y roles**, **Tokens de API y webhooks**, **Credenciales DTE** (cargar o rotar el certificado y las contraseñas; nunca mostrarlas de vuelta).
11. **Suscripción**: plan, consumo de documentos del mes, facturas de Escobar Net (emitidas con la misma plataforma).
12. **Bitácora**: quién hizo qué y cuándo.

#### 19.2 Superadmin (Escobar Net)
- Empresas: alta, suspensión, plan, consumo.
- Estado de Hacienda (latencia y errores por endpoint), colas y contingencias activas.
- Métricas: documentos por día, rechazos más comunes por `codigoMsg`.
- Soporte: ver documentos de una empresa **solo con el permiso temporal que la empresa otorga**, sin ver sus credenciales.

#### 19.3 API REST pública (para POS y ERP)
- Autenticación por token de empresa (Sanctum), con permisos por token.
- `POST /api/v1/dte/{tipo}`: emite. Recibe datos de negocio (ítems, cliente, pagos); **la plataforma calcula los totales**. Opción `calcular=false` para clientes que mandan sus totales (se validan con holgura).
- `GET /api/v1/dte/{codigo}`, `GET /api/v1/dte/{codigo}/pdf` y `GET /api/v1/dte/{codigo}/json`.
- `POST /api/v1/dte/{codigo}/invalidar`.
- `GET /api/v1/catalogos/{cat}`.
- Webhooks: `dte.transmitido`, `dte.rechazado`, `dte.invalidado`, `contingencia.iniciada` y `contingencia.finalizada`, firmados con HMAC.
- Idempotencia: cabecera `Idempotency-Key`. La misma clave nunca genera dos documentos.
- Documentación OpenAPI y SDK de ejemplo en PHP y JavaScript.

#### 19.4 Planes (configurables, no fijos en el código)
- Tabla `planes`: límite de documentos al mes, establecimientos, usuarios, si incluye API y si incluye POS.
- Si se excede el límite, se avisa y se cobra por documento adicional. **Nunca se bloquea la emisión de una venta por el límite del plan**: es obligación legal del cliente emitir. Se factura el excedente.

---

### 20. Modelo de datos (mínimo)
- `empresas`, `empresa_credenciales` (cifrado), `establecimientos`, `puntos_venta`, `usuarios`, `empresa_usuario` (rol), `tokens_api`, `webhooks`.
- `productos`, `clientes`.
- `dtes`: `id`, `empresa_id`, `establecimiento_id`, `punto_venta_id`, `tipo`, `version`, `ambiente`, `codigo_generacion` (único), `numero_control` (único por empresa), `fecha_emision`, `hora_emision`, `estado`, `modelo`, `operacion`, `receptor_*` (nombre y documento, para búsqueda), `total_gravada`, `total_exenta`, `total_no_suj`, `total_iva`, `total_pagar` (`NUMERIC`), `json_original` (jsonb), `jws`, `sello_recibido`, `fh_procesamiento`, `respuesta_mh` (jsonb), `observaciones_mh` (jsonb), `archivo_json_sha256`, `pdf_path`, `relacionado_con` y timestamps.
- `dte_items` (para reportes), `dte_pagos`, `dte_relacionados`.
- `eventos`: invalidación, contingencia, retorno y operaciones especiales, con su propio código, JWS, sello y estado.
- `lotes` (código de lote y estado), `contingencias` (inicio, fin, tipo, motivo, documentos).
- `correlativos`, `cat_*`, `planes`, `suscripciones`, `consumos`, `bitacora` (append-only), `dte_eventos_internos`.
- Todas las tablas de negocio llevan `empresa_id` más RLS. Índices por `(empresa_id, fecha_emision)` y `(empresa_id, estado)`.

---

### 21. Requisitos no funcionales
- Emitir una FE completa (generar, firmar, transmitir, responder) en **menos de 3 s (p95)** cuando Hacienda responde normal.
- Soportar 50 documentos por segundo por instancia en colas, con escalado horizontal.
- Disponibilidad objetivo de 99.5% y monitoreo de los endpoints de Hacienda cada minuto.
- Respaldos diarios con retención de 30 días, más el archivo inmutable de los DTE por 10 años.
- La interfaz va en español, adaptada al celular, accesible y con formatos de El Salvador: fechas `dd/mm/aaaa` en pantalla, moneda `$1,234.56`.
- Todo el texto legal y los mensajes de error de Hacienda se traducen al cliente en lenguaje simple.

<<<FIN ESPECIFICACION.md>>>

---

## PARTE 3 — PLAN.md (guardar como `PLAN.md`)

<<<INICIO PLAN.md>>>
## PLAN.md — Plataforma SaaS DTE · Escobar Net

Trabaja **un paso a la vez**. Un paso termina solo cuando pasan todas sus pruebas de «Terminado cuando». Al terminar cada paso, actualiza la sección «Estado» al final de este archivo.

La especificación completa está en `ESPECIFICACION.md` y las reglas permanentes en `CLAUDE.md`.

### Decisiones tomadas
- **Modelo:** SaaS multiempresa. Una plataforma y una base de datos, con aislamiento por `empresa_id`, scope global y Row Level Security. El mismo código soporta `APP_MODE=dedicado` para instalar una empresa sola.
- **Base de datos:** PostgreSQL. **Colas y caché:** Redis y Horizon. **Archivos:** almacenamiento S3 compatible (MinIO en local).
- **Firma:** firmador oficial de Hacienda en Docker (interno).
- **Frontend del panel:** Laravel con Livewire y Tailwind (un solo stack, más fácil para el equipo). La API REST va aparte, con Sanctum.
- **PDF:** generación del lado del servidor (Browsershot/Chromium o DomPDF). Elegir uno en el Paso 8 y documentar el porqué.

### Decisiones pendientes (preguntar, no inventar)
1. **Modo de correlativo** por defecto (`por_tipo` frente a `por_establecimiento`): confirmar con Hacienda en el ambiente de pruebas. Implementar los tres modos.
2. **Hosting de producción:** proveedor y país, con tratados con El Salvador. Antes del Paso 14.
3. **Pasarela de cobro** de las suscripciones: antes del Paso 13.
4. **WhatsApp** como medio de entrega: proveedor oficial de la API de WhatsApp Business. Queda para después del MVP.

---

### FASE A — Núcleo (lo mínimo para emitir una factura real en pruebas)

#### Paso 0 — Proyecto base
- Laravel en la versión estable actual y PHP 8.2 o superior, con PostgreSQL, Redis, MinIO, firmador y Mailpit en `docker-compose.yml`.
- Pest, Pint y Larastan (nivel 6) configurados, con integración continua (GitHub Actions) que corre todo en cada push.
- `config/dte.php` con las URL, timeouts, reintentos, horas de token y tamaño de lote.

**Terminado cuando:** `docker compose up` deja todo arriba, `php artisan test` corre en verde y el CI pasa.

#### Paso 1 — Multiempresa y seguridad
- Tablas `empresas`, `usuarios`, `empresa_usuario` (rol), `establecimientos` y `puntos_venta`.
- `EmpresaScope`, middleware que fija `SET app.empresa_id`, políticas RLS y helper para los jobs.
- Login con 2FA obligatoria para los roles `dueno` y `superadmin`. Cambio de empresa activa.
- `empresa_credenciales` cifradas con `DTE_SECRETS_KEY`, con disco privado para los `.crt`.
- Bitácora append-only.

**Terminado cuando:**
- Las pruebas demuestran que la empresa A no puede leer ni escribir datos de la B, ni por Eloquent ni por SQL directo (RLS).
- Las credenciales no aparecen en `toArray`, `toJson`, los logs ni las excepciones.
- La bitácora rechaza `UPDATE` y `DELETE`.

#### Paso 2 — Motor de cálculo (integrar el paso 1 existente)
- Traer `Decimal`, `DteJson`, `FacturaBuilder`, el esquema `fe-*.json` y sus 30 pruebas desde `escobarnet-dte-paso1`.
- `codigoGeneracion` en MAYÚSCULAS.
- Validador de esquemas (`opis/json-schema`) con un registro de esquemas por tipo y versión en `resources/schemas/`.
- `NumeroALetras` en español para `totalLetras` (montos en dólares y centavos).

**Terminado cuando:** pasan las 30 pruebas existentes, más las del UUID, `NumeroALetras` (0.01, 1, 21, 100, 1,000,000.99) y la validación negativa de esquema.

#### Paso 3 — Catálogos y correlativos
- Seeders de CAT-001 a CAT-033 desde `docs/oficial/Catalogos.xlsx`, con el comando `dte:catalogos:actualizar`.
- Tabla `correlativos` con sus 3 modos, `FOR UPDATE` y reinicio anual.
- Generador de `numeroControl`.

**Terminado cuando:**
- 50 emisiones concurrentes no producen números repetidos.
- El cambio de año reinicia el correlativo en 1.
- El conteo de filas por catálogo coincide con el Excel.

#### Paso 4 — Firmador y cliente de Hacienda
- `FirmadorClient` y `HaciendaClient` con todos los servicios de §8.2, el token en caché por empresa y la política de §8.3.
- La protección contra bloqueo (3 logins fallidos) detiene los intentos y alerta.
- Tabla `dtes` y enum `EstadoDte`. Job `TransmitirDte` en la cola `transmision`.
- Registro de la respuesta completa de Hacienda. Mapeo de `codigoMsg` a mensajes en español.

**Terminado cuando:**
- Con `Http::fake` pasan los casos de éxito, rechazo, timeout seguido de consulta, timeout seguido de 2 reenvíos y contingencia, token vencido y login fallido 3 veces.
- La prueba del grupo `hacienda-pruebas` (credenciales en `.env`) obtiene un sello real para una FE.

### FASE B — Todos los documentos y eventos

#### Paso 5 — Crédito Fiscal, Notas y Remisión
Builders y pruebas de CCF (03), NC (05), ND (06) y NR (04), con documentos relacionados y percepción y retención.

**Terminado cuando:**
- Cada uno valida contra su esquema oficial.
- Los ejemplos de ESPECIFICACION §6.4 cuadran al centavo ($7,039.90, $2,599.00, $761.20, $107.35) y el de NC da $95.80.

#### Paso 6 — Resto de documentos
FSE (14), FEX (11), CR (07), CL (08), DCL (09) y CD (15).

**Terminado cuando:** cada tipo tiene al menos 5 pruebas de cálculo, la validación de esquema y una transmisión exitosa en el grupo `hacienda-pruebas`.

#### Paso 7 — Eventos
- Invalidación con validación de plazos (Cuadro 6, incluidos días hábiles y los 2 años de medicamentos).
- Contingencia completa (§10): detección, documentos transitorios, scheduler cada 15 minutos, evento, lotes dentro de 72 h y alerta a los 3 días con el borrador del informe.
- Retorno y Operaciones Especiales.
- Envío por lotes de 100 con `consultadtelote`.

**Terminado cuando:**
- Las pruebas de borde de cada plazo pasan (último día válido y primer día inválido).
- La simulación de 3 horas sin servicio termina con todos los documentos en `transmitido`.
- Un retorno no cambia el estado del original a `invalidado`.

### FASE C — Entrega y producto

#### Paso 8 — Representación gráfica y entrega
- `config/dte/campos.php` (A/B/C/D por tipo y campo, extraído de los Anexos II y III).
- Plantillas PDF por tipo, en carta y en ticket de 80 mm, con QR, marca de agua de pruebas y aviso de contingencia.
- Correo de entrega sin publicidad, con el JSON y el PDF adjuntos. Portal público de descarga con enlace firmado y QR.
- Archivo inmutable en S3 con hash SHA-256.

**Terminado cuando:**
- El QR decodifica a la URL correcta.
- El JSON adjunto es idéntico al transmitido (prueba por hash).
- El PDF de pruebas lleva la marca de agua.
- Una prueba visual (snapshot) cubre cada tipo.

#### Paso 9 — Panel: emitir, POS y consultas
Módulos 1, 2, 3, 4, 6, 7 y 8 de ESPECIFICACION §19.1.

**Terminado cuando:**
- Las pruebas de Livewire cubren emitir FE y CCF de punta a punta.
- El POS emite y, sin conexión, deja el documento en contingencia.
- Los listados filtran y exportan a Excel.

#### Paso 10 — Onboarding asistido y pruebas mínimas
- Asistente de §18. Comando y pantalla `dte:pruebas-minimas {empresa} [--tipos=] [--dry-run]`, con barra de avance y reanudación si se corta.

**Terminado cuando:** `--dry-run` genera documentos válidos en las cantidades exactas, y la ejecución real en pruebas completa al menos FE y CCF.

#### Paso 11 — Recibidos y reportes
- Importación de los DTE recibidos (subir JSON y buzón de correo) con verificación de sello.
- Libros de IVA, retenciones y percepciones, resumen para el F-07, en Excel y CSV.

**Terminado cuando:** con un conjunto de datos de prueba, los totales del libro cuadran con la suma de los documentos.

#### Paso 12 — API pública y webhooks
§19.3 completo, con OpenAPI, idempotencia y webhooks firmados.

**Terminado cuando:**
- Las pruebas de la API cubren la emisión, la idempotencia (la misma clave da el mismo documento) y los permisos por token.
- El webhook se reintenta con backoff.

#### Paso 13 — Planes, suscripciones y superadmin
§19.2 y §19.4. Escobar Net factura a sus clientes con su propia plataforma.

**Terminado cuando:**
- Exceder el plan no bloquea la emisión y registra el excedente.
- El superadmin no puede ver credenciales (prueba).
- El acceso de soporte exige un permiso temporal de la empresa.

### FASE D — Producción

#### Paso 14 — Endurecimiento y despliegue
- WAF y límite de peticiones, cabeceras de seguridad, rotación de llaves, respaldos con prueba de restauración.
- Monitoreo con alertas, healthcheck de Hacienda cada minuto y runbook de incidentes.
- Infraestructura como código. Entornos `staging` y `production`.
- Revisión de seguridad: OWASP ASVS nivel 2.

**Terminado cuando:**
- Una restauración de respaldo en staging funciona.
- Una prueba de carga sostiene 50 documentos por segundo con Hacienda simulada.
- La checklist de seguridad está completa.

#### Paso 15 — Piloto
Una o dos empresas reales en pruebas, después en producción. Recoger los `codigoMsg` reales y completar el mapeo de mensajes.

---

### Estado
- [ ] Paso 0 · [ ] 1 · [ ] 2 · [ ] 3 · [ ] 4 · [ ] 5 · [ ] 6 · [ ] 7 · [ ] 8 · [ ] 9 · [ ] 10 · [ ] 11 · [ ] 12 · [ ] 13 · [ ] 14 · [ ] 15

<<<FIN PLAN.md>>>

---

## PARTE 4 — Código del Paso 1 (ya probado: 30 pruebas en verde)

Guarda cada bloque en `escobarnet-dte-paso1/<ruta>`. En el Paso 2 del plan, intégralo al proyecto Laravel. Es código probado: **no lo reescribas**. Solo ajústalo donde el plan lo indique (por ejemplo, el UUID en mayúsculas).

### Archivo: `README.md`

````markdown
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
````

### Archivo: `config/dte.php`

```php
<?php

/*
|--------------------------------------------------------------------------
| Configuración del motor DTE de Escobar Net
|--------------------------------------------------------------------------
| Valores tomados del "Manual Tecnológico para la Integración del Sistema
| de Transmisión V2.0" (Ministerio de Hacienda, mayo 2026).
|
| Las credenciales de cada negocio (contraseña API, clave privada del
| certificado) NO van aquí: se guardan cifradas en la base de datos, una por
| cliente. Este archivo solo tiene la configuración general del motor.
*/

return [

    // "00" = pruebas, "01" = producción. Nunca enviar DTE de prueba a producción.
    'ambiente' => env('DTE_AMBIENTE', '00'),

    'api' => [
        'base_url' => [
            '00' => 'https://apitest.dtes.mh.gob.sv',
            '01' => 'https://api.dtes.mh.gob.sv',
        ],
        // Las rutas van exactas y SIN barra al final (Manual Tecnológico, 6.2).
        'rutas' => [
            'autenticacion' => '/seguridad/auth',
            'recepcion' => '/fesv/recepciondte',
            'recepcion_lote' => '/fesv/recepcionlote',
            'consulta' => '/fesv/recepcion/consultadte',
            'consulta_lote' => '/fesv/recepcion/consultadtelote/{codigoLote}',
            'contingencia' => '/fesv/contingencia',
            'invalidacion' => '/fesv/anulardte',
        ],
        'user_agent' => env('DTE_USER_AGENT', 'EscobarNet-MotorDTE/1.0'),

        // Política de reintentos: esperar 8 segundos; si no hay respuesta,
        // consultar y reenviar hasta 2 veces; luego, contingencia.
        'timeout_segundos' => 8,
        'reintentos' => 2,

        // Vigencia del token: 48 h en pruebas, 24 h en producción.
        // Se renueva un poco antes para no quedar a medias.
        'token_horas' => ['00' => 47, '01' => 23],
    ],

    'firmador' => [
        // Firmador oficial de Hacienda (svfe-api-firmador) corriendo en Docker.
        'url' => env('DTE_FIRMADOR_URL', 'http://localhost:8113/firmardocumento/'),
        'timeout_segundos' => 10,
    ],

    // Carpeta con los JSON Schemas oficiales (descargados de factura.gob.sv).
    'esquemas' => storage_path('app/dte/schemas'),

    'zona_horaria' => 'America/El_Salvador',
];
```

### Archivo: `app/Console/Commands/DteDemoFactura.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Dte\Excepciones\DteException;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Validacion\ValidadorEsquema;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Demostración: arma la factura de la pupusería del curso (módulo 7),
 * la muestra y la valida contra el esquema oficial de Hacienda.
 *
 *   php artisan dte:demo-factura
 */
final class DteDemoFactura extends Command
{
    protected $signature = 'dte:demo-factura {--compacto : Muestra el JSON en una sola línea}';

    protected $description = 'Arma y valida la Factura (01) de ejemplo de la pupusería';

    public function handle(): int
    {
        // Datos de DEMOSTRACIÓN. Los códigos de municipio y distrito deben
        // tomarse del catálogo oficial (CAT-013 y CAT-008).
        $emisor = new Emisor(
            nit: '06140000000000',
            nrc: '1234567',
            nombre: 'PUPUSERIA LA ESQUINA S.A. DE C.V.',
            codActividad: '56101',
            descActividad: 'Restaurantes',
            nombreComercial: 'Pupusería La Esquina',
            departamento: '06',
            municipio: '23',
            distrito: '14',
            complemento: 'Calle Principal 123, San Salvador',
            telefono: '22220000',
            correo: 'ventas@laesquina.com',
            codEstable: 'M001',
            codPuntoVenta: 'P001',
        );

        $venta = new Venta(
            lineas: [
                new LineaVenta('Pupusa revuelta', '6', '0.75'),
                new LineaVenta('Horchata', '2', '1.00'),
            ],
            receptor: Receptor::consumidorFinal('cliente@correo.com'),
            correlativo: 1,
            fechaHora: new DateTimeImmutable('now', new DateTimeZone(config('dte.zona_horaria'))),
        );

        try {
            $factura = (new FacturaBuilder($emisor, config('dte.ambiente')))->construir($venta);
        } catch (DteException $e) {
            $this->error('No se pudo armar la factura: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->option('compacto') ? $factura->json : $factura->jsonLegible());
        $this->newLine();
        $this->info("Número de control:  {$factura->numeroControl}");
        $this->info("Código generación:  {$factura->codigoGeneracion}");
        $this->info("Total a pagar:      \${$factura->totalPagar->toFixed(2)}");
        $this->info("IVA incluido:       \${$factura->totalIva->toFixed(2)}");

        if (!class_exists(\Opis\JsonSchema\Validator::class)) {
            $this->warn('Para validar contra el esquema oficial instale: composer require opis/json-schema');

            return self::SUCCESS;
        }

        $errores = (new ValidadorEsquema(config('dte.esquemas')))->validar($factura->json, '01');
        if ($errores === []) {
            $this->info('✔ La factura cumple el esquema oficial fe-f-v2.json de Hacienda.');

            return self::SUCCESS;
        }

        $this->error('✘ La factura NO cumple el esquema oficial:');
        foreach ($errores as $campo => $mensajes) {
            $this->line("  {$campo}: " . implode('; ', $mensajes));
        }

        return self::FAILURE;
    }
}
```

### Archivo: `app/Dte/Catalogos/Catalogo.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Catalogos;

/**
 * Catálogos oficiales más usados, copiados del Excel
 * "Catálogos – Facturación Electrónica" del Ministerio de Hacienda (julio 2026).
 *
 * Los catálogos largos (CAT-008 Distrito, CAT-013 Municipio, CAT-019 Actividad
 * económica, CAT-020 País) deben cargarse desde el archivo oficial a la base de
 * datos; no se escriben a mano aquí.
 */
final class Catalogo
{
    /** CAT-001 Ambiente de destino */
    public const AMBIENTE = [
        '00' => 'Modo prueba',
        '01' => 'Modo producción',
    ];

    /** CAT-002 Tipo de documento / evento */
    public const TIPO_DTE = [
        '01' => 'Factura',
        '03' => 'Comprobante de crédito fiscal',
        '04' => 'Nota de remisión',
        '05' => 'Nota de crédito',
        '06' => 'Nota de débito',
        '07' => 'Comprobante de retención',
        '08' => 'Comprobante de liquidación',
        '09' => 'Documento contable de liquidación',
        '11' => 'Facturas de exportación',
        '14' => 'Factura de sujeto excluido',
        '15' => 'Comprobante de donación',
        '17' => 'Evento de Operaciones Especiales',
        '18' => 'Evento de Retorno',
    ];

    /** CAT-003 Modelo de facturación */
    public const MODELO_FACTURACION = [
        1 => 'Modelo Facturación previo',
        2 => 'Modelo Facturación diferido',
    ];

    /** CAT-004 Tipo de transmisión */
    public const TIPO_TRANSMISION = [
        1 => 'Transmisión normal',
        2 => 'Transmisión por contingencia',
    ];

    /** CAT-005 Tipo de contingencia */
    public const TIPO_CONTINGENCIA = [
        1 => 'No disponibilidad de sistema del MH',
        2 => 'No disponibilidad de sistema del emisor',
        3 => 'Falla en el suministro de servicio de Internet del Emisor',
        4 => 'Falla en el suministro de servicio de energía eléctrica del emisor que impida la transmisión de los DTE',
        5 => 'Otro',
    ];

    /** CAT-011 Tipo de ítem */
    public const TIPO_ITEM = [
        1 => 'Bienes',
        2 => 'Servicios',
        3 => 'Ambos (Bienes y Servicios)',
        4 => 'Otros tributos por ítem',
    ];

    /** CAT-012 Departamento */
    public const DEPARTAMENTO = [
        '00' => 'Otro (Para extranjeros)',
        '01' => 'Ahuachapán', '02' => 'Santa Ana', '03' => 'Sonsonate', '04' => 'Chalatenango',
        '05' => 'La Libertad', '06' => 'San Salvador', '07' => 'Cuscatlán', '08' => 'La Paz',
        '09' => 'Cabañas', '10' => 'San Vicente', '11' => 'Usulután', '12' => 'San Miguel',
        '13' => 'Morazán', '14' => 'La Unión',
    ];

    /** CAT-014 Unidad de medida (las más usadas; la lista completa está en el Excel oficial) */
    public const UNIDAD_MEDIDA = [
        1 => 'metro', 13 => 'metro cuadrado', 22 => 'Galón', 23 => 'Litro', 34 => 'Kilogramo',
        36 => 'Libra', 39 => 'Gramo', 58 => 'Docena', 59 => 'Unidad', 99 => 'Otra',
    ];

    /** CAT-016 Condición de la operación */
    public const CONDICION_OPERACION = [
        1 => 'Contado',
        2 => 'A crédito',
        3 => 'Otro',
    ];

    /** CAT-017 Forma de pago */
    public const FORMA_PAGO = [
        '01' => 'Billetes y monedas',
        '02' => 'Tarjeta Débito',
        '03' => 'Tarjeta Crédito',
        '04' => 'Cheque',
        '05' => 'Transferencia-Depósito Bancario',
        '08' => 'Dinero electrónico',
        '09' => 'Monedero electrónico',
        '11' => 'Bitcoin',
        '12' => 'Otras Criptomonedas',
        '13' => 'Cuentas por pagar del receptor',
        '14' => 'Giro bancario',
        '99' => 'Otros (se debe indicar el medio de pago)',
    ];

    /** CAT-022 Tipo de documento de identificación */
    public const TIPO_DOC_IDENTIFICACION = [
        '36' => 'NIT',
        '13' => 'DUI',
        '37' => 'Otro',
        '03' => 'Pasaporte',
        '02' => 'Carnet de Residente',
    ];

    /** CAT-024 Motivo del evento (invalidación) */
    public const MOTIVO_INVALIDACION = [
        1 => 'Error en la Información del Documento Tributario Electrónico a invalidar.',
        2 => 'Rescindir de la operación realizada.',
        3 => 'Otro',
    ];

    public static function existe(array $catalogo, string|int $codigo): bool
    {
        return array_key_exists($codigo, $catalogo);
    }
}
```

### Archivo: `app/Dte/Excepciones/DteException.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Excepciones;

use RuntimeException;

/**
 * Error de negocio del motor DTE, con un mensaje que se le puede mostrar
 * al cajero tal cual (en español y sin términos técnicos).
 */
final class DteException extends RuntimeException
{
}
```

### Archivo: `app/Dte/Factura/FacturaBuilder.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Factura;

use App\Dte\Catalogos\Catalogo;
use App\Dte\Excepciones\DteException;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Pago;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\Decimal;
use App\Dte\Support\DteJson;
use App\Dte\Support\NumeroALetras;
use App\Dte\Support\NumeroControl;
use DateTimeZone;

/**
 * Arma una Factura Electrónica (tipo 01, esquema versión 2) a partir de una
 * venta simple, con todos los cálculos exactos.
 *
 * Reglas aplicadas (Manual Funcional del Sistema de Transmisión v2.0):
 *   - En la Factura el precio unitario INCLUYE el IVA.
 *   - IVA contenido por línea = ventaGravada × 13 / 113, con 8 decimales.
 *   - Montos de cada línea: hasta 8 decimales. Montos del resumen: 2 decimales.
 *   - Redondeo HALF_UP. Holgura permitida en el resumen: 1 centavo.
 *   - Consumidor final con compra menor a $25,000: no se exige identificación.
 *
 * Este primer paso cubre ventas gravadas sin descuentos ni tributos especiales
 * (el caso de la mayoría de pupuserías, tiendas y restaurantes). Ventas
 * exentas, no sujetas, descuentos y retenciones se agregan después.
 */
final class FacturaBuilder
{
    public const TIPO_DTE = '01';
    public const VERSION = 2;

    private const ESCALA_ITEM = 8;
    private const ESCALA_RESUMEN = 2;
    private const LIMITE_SIN_IDENTIFICAR = '25000';

    public function __construct(
        private readonly Emisor $emisor,
        private readonly string $ambiente = '00',
    ) {
        if (!Catalogo::existe(Catalogo::AMBIENTE, $ambiente)) {
            throw new DteException("Ambiente inválido: {$ambiente}. Use 00 (pruebas) o 01 (producción).");
        }
    }

    public function construir(Venta $venta, ?string $codigoGeneracion = null): FacturaResultado
    {
        $codigoGeneracion ??= CodigoGeneracion::nuevo();
        if (!CodigoGeneracion::esValido($codigoGeneracion)) {
            throw new DteException('Código de generación inválido: debe ser un UUID v4 en mayúsculas.');
        }

        $this->validarLineas($venta->lineas);

        // --- Cuerpo del documento ---------------------------------------
        $cuerpo = [];
        $sumaGravada = Decimal::zero();
        $sumaIvaItems = Decimal::zero();

        foreach (array_values($venta->lineas) as $i => $linea) {
            $cantidad = $linea->cantidad->round(self::ESCALA_ITEM);
            $precio = $linea->precioUnitario->round(self::ESCALA_ITEM);
            $ventaGravada = $cantidad->mul($precio)->round(self::ESCALA_ITEM);
            $ivaItem = self::ivaContenido($ventaGravada, self::ESCALA_ITEM);

            $sumaGravada = $sumaGravada->add($ventaGravada);
            $sumaIvaItems = $sumaIvaItems->add($ivaItem);

            $cuerpo[] = [
                'numItem' => $i + 1,
                'tipoItem' => $linea->tipoItem,
                'numeroDocumento' => null,
                'cantidad' => $cantidad,
                'codigo' => $linea->codigo,
                'codTributo' => null,
                'uniMedida' => $linea->uniMedida,
                'descripcion' => $linea->descripcion,
                'precioUni' => $precio,
                'montoDescu' => Decimal::zero(),
                'ventaNoSuj' => Decimal::zero(),
                'ventaExenta' => Decimal::zero(),
                'ventaGravada' => $ventaGravada,
                'tributos' => null,
                'psv' => Decimal::zero(),
                'noGravado' => Decimal::zero(),
                'ivaItem' => $ivaItem,
            ];
        }

        // --- Resumen ------------------------------------------------------
        $totalGravada = $sumaGravada->round(self::ESCALA_RESUMEN);
        $subTotalVentas = $totalGravada;            // + no sujetas + exentas (cero por ahora)
        $totalDescu = Decimal::zero();
        $subTotal = $subTotalVentas->sub($totalDescu);
        $totalIva = self::ivaContenido($subTotal, self::ESCALA_RESUMEN);
        $montoTotalOperacion = $subTotal;           // en Factura el IVA ya va incluido
        $ivaRete = Decimal::zero();
        $totalNoGravado = Decimal::zero();
        $totalPagar = $montoTotalOperacion->sub($ivaRete)->add($totalNoGravado);

        // Control interno: la suma del IVA de las líneas debe quedar dentro
        // de la holgura de 1 centavo respecto al IVA del resumen.
        if ($sumaIvaItems->sub($totalIva)->abs()->compareTo('0.01') > 0) {
            throw new DteException('El IVA de las líneas no cuadra con el IVA total (diferencia mayor a 1 centavo).');
        }

        $this->validarReceptor($venta->receptor, $totalPagar);
        $pagos = $this->construirPagos($venta->pagos, $totalPagar);

        if (!Catalogo::existe(Catalogo::CONDICION_OPERACION, $venta->condicionOperacion)) {
            throw new DteException("Condición de operación inválida: {$venta->condicionOperacion}");
        }

        $fecha = $venta->fechaHora->setTimezone(new DateTimeZone('America/El_Salvador'));
        $numeroControl = NumeroControl::generar(
            self::TIPO_DTE,
            $this->emisor->codEstable,
            $this->emisor->codPuntoVenta,
            $venta->correlativo,
        );

        $documento = [
            'identificacion' => [
                'version' => self::VERSION,
                'ambiente' => $this->ambiente,
                'tipoDte' => self::TIPO_DTE,
                'numeroControl' => $numeroControl,
                'codigoGeneracion' => $codigoGeneracion,
                'tipoModelo' => 1,          // previo (transmisión normal)
                'tipoOperacion' => 1,       // normal
                'tipoContingencia' => null,
                'motivoContin' => null,
                'fecEmi' => $fecha->format('Y-m-d'),
                'horEmi' => $fecha->format('H:i:s'),
                'tipoMoneda' => 'USD',
            ],
            'documentoRelacionado' => null,
            'emisor' => $this->emisorComoArreglo(),
            'receptor' => $this->receptorComoArreglo($venta->receptor),
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => [
                'totalNoSuj' => Decimal::zero(),
                'totalExenta' => Decimal::zero(),
                'totalGravada' => $totalGravada,
                'subTotalVentas' => $subTotalVentas,
                'descuNoSuj' => Decimal::zero(),
                'descuExenta' => Decimal::zero(),
                'descuGravada' => Decimal::zero(),
                'porcentajeDescuento' => Decimal::zero(),
                'totalDescu' => $totalDescu,
                'tributos' => null,
                'subTotal' => $subTotal,
                'ivaRete' => $ivaRete,
                'montoTotalOperacion' => $montoTotalOperacion,
                'totalNoGravado' => $totalNoGravado,
                'totalPagar' => $totalPagar,
                'totalLetras' => NumeroALetras::convertir($totalPagar),
                'totalIva' => $totalIva,
                'saldoFavor' => Decimal::zero(),
                'condicionOperacion' => $venta->condicionOperacion,
                'pagos' => $pagos,
                'numPagoElectronico' => null,
                'observaciones' => $venta->observaciones,
            ],
            'apendice' => null,
        ];

        return new FacturaResultado(
            codigoGeneracion: $codigoGeneracion,
            numeroControl: $numeroControl,
            totalPagar: $totalPagar,
            totalIva: $totalIva,
            json: DteJson::encode($documento),
        );
    }

    /** IVA contenido en un monto que ya incluye IVA: monto × 13 / 113. */
    public static function ivaContenido(Decimal $montoConIva, int $escala): Decimal
    {
        return $montoConIva->mul(13)->div(113, $escala);
    }

    // ------------------------------------------------------------------

    /** @param LineaVenta[] $lineas */
    private function validarLineas(array $lineas): void
    {
        if (count($lineas) === 0) {
            throw new DteException('La factura debe tener al menos un producto o servicio.');
        }
        if (count($lineas) > 2000) {
            throw new DteException('Una factura puede tener como máximo 2,000 líneas.');
        }

        foreach (array_values($lineas) as $i => $linea) {
            $n = $i + 1;
            if (!$linea instanceof LineaVenta) {
                throw new DteException("La línea {$n} no es válida.");
            }
            if (!$linea->cantidad->isPositive()) {
                throw new DteException("Línea {$n}: la cantidad debe ser mayor que cero.");
            }
            if ($linea->precioUnitario->isNegative()) {
                throw new DteException("Línea {$n}: el precio no puede ser negativo.");
            }
            $largo = mb_strlen(trim($linea->descripcion));
            if ($largo < 1 || $largo > 1500) {
                throw new DteException("Línea {$n}: la descripción debe tener entre 1 y 1,500 caracteres.");
            }
            if ($linea->uniMedida < 1 || $linea->uniMedida > 99) {
                throw new DteException("Línea {$n}: unidad de medida inválida (catálogo CAT-014).");
            }
            if (!in_array($linea->tipoItem, [1, 2, 3], true)) {
                throw new DteException("Línea {$n}: tipo de ítem inválido (1 bienes, 2 servicios, 3 ambos).");
            }
            if ($linea->codigo !== null && (mb_strlen($linea->codigo) < 1 || mb_strlen($linea->codigo) > 25)) {
                throw new DteException("Línea {$n}: el código del producto debe tener entre 1 y 25 caracteres.");
            }
        }
    }

    private function validarReceptor(Receptor $receptor, Decimal $totalPagar): void
    {
        if ($totalPagar->compareTo(self::LIMITE_SIN_IDENTIFICAR) >= 0 && !$receptor->estaIdentificado()) {
            throw new DteException('Para ventas de $25,000 o más hay que identificar al cliente (tipo y número de documento, y nombre).');
        }
        if ($receptor->tipoDocumento !== null
            && !Catalogo::existe(Catalogo::TIPO_DOC_IDENTIFICACION, $receptor->tipoDocumento)) {
            throw new DteException("Tipo de documento del cliente inválido: {$receptor->tipoDocumento} (catálogo CAT-022).");
        }
        if ($receptor->correo !== null && !filter_var($receptor->correo, FILTER_VALIDATE_EMAIL)) {
            throw new DteException("El correo del cliente no es válido: {$receptor->correo}");
        }
    }

    /**
     * @param Pago[] $pagos
     * @return array<int, array<string, mixed>>
     */
    private function construirPagos(array $pagos, Decimal $totalPagar): array
    {
        if ($pagos === []) {
            $pagos = [new Pago('01')];
        }

        $resultado = [];
        $suma = Decimal::zero();
        foreach ($pagos as $pago) {
            if (!Catalogo::existe(Catalogo::FORMA_PAGO, $pago->codigo)) {
                throw new DteException("Forma de pago inválida: {$pago->codigo} (catálogo CAT-017).");
            }
            $monto = ($pago->monto ?? (count($pagos) === 1 ? $totalPagar : null));
            if ($monto === null) {
                throw new DteException('Con varias formas de pago, cada una debe indicar su monto.');
            }
            $monto = $monto->round(self::ESCALA_RESUMEN);
            $suma = $suma->add($monto);
            $resultado[] = [
                'codigo' => $pago->codigo,
                'montoPago' => $monto,
                'referencia' => $pago->referencia,
                'plazo' => $pago->plazo,
                'periodo' => $pago->periodo,
            ];
        }

        if (!$suma->equals($totalPagar)) {
            throw new DteException("Los pagos suman \${$suma->toFixed(2)} pero el total es \${$totalPagar->toFixed(2)}.");
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function emisorComoArreglo(): array
    {
        $e = $this->emisor;

        return [
            'nit' => $e->nit,
            'nrc' => $e->nrc,
            'nombre' => $e->nombre,
            'codActividad' => $e->codActividad,
            'descActividad' => $e->descActividad,
            'nombreComercial' => $e->nombreComercial,
            'direccion' => [
                'departamento' => $e->departamento,
                'municipio' => $e->municipio,
                'distrito' => $e->distrito,
                'complemento' => $e->complemento,
            ],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
            'codEstable' => $e->codEstable,
            'codPuntoVenta' => $e->codPuntoVenta,
        ];
    }

    /** @return array<string, mixed> */
    private function receptorComoArreglo(Receptor $r): array
    {
        return [
            'tipoDocumento' => $r->tipoDocumento,
            'numDocumento' => $r->numDocumento,
            'nrc' => $r->nrc,
            'nombre' => $r->nombre,
            'codActividad' => $r->codActividad,
            'descActividad' => $r->descActividad,
            'direccion' => $r->tieneDireccion() ? [
                'departamento' => $r->departamento,
                'municipio' => $r->municipio,
                'distrito' => $r->distrito,
                'complemento' => $r->complemento,
            ] : null,
            'telefono' => $r->telefono,
            'correo' => $r->correo,
        ];
    }
}
```

### Archivo: `app/Dte/Factura/FacturaResultado.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Factura;

use App\Dte\Support\Decimal;

/**
 * Lo que devuelve el FacturaBuilder: el JSON listo para firmar y los datos
 * que el sistema guarda en su base de datos.
 */
final readonly class FacturaResultado
{
    public function __construct(
        public string $codigoGeneracion,
        public string $numeroControl,
        public Decimal $totalPagar,
        public Decimal $totalIva,
        public string $json,
    ) {
    }

    /** El documento como arreglo (para mostrarlo o inspeccionarlo). */
    public function documento(): array
    {
        return json_decode($this->json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** El mismo JSON con sangría, para leerlo. */
    public function jsonLegible(): string
    {
        return json_encode(
            json_decode($this->json, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
```

### Archivo: `app/Dte/Modelos/Emisor.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

/**
 * Datos del negocio que emite el DTE. En el sistema real se guardan en la
 * base de datos (pantalla "Configuración del emisor") y se cargan por cliente.
 */
final readonly class Emisor
{
    public function __construct(
        public string $nit,             // 14 dígitos, sin guiones
        public string $nrc,             // sin guiones
        public string $nombre,          // razón social
        public string $codActividad,    // catálogo CAT-019
        public string $descActividad,
        public ?string $nombreComercial,
        public string $departamento,    // catálogo CAT-012
        public string $municipio,       // catálogo CAT-013
        public string $distrito,        // catálogo CAT-008
        public string $complemento,     // calle, número, colonia
        public string $telefono,
        public string $correo,
        public string $codEstable,      // ejemplo: M001
        public string $codPuntoVenta,   // ejemplo: P001
    ) {
    }
}
```

### Archivo: `app/Dte/Modelos/LineaVenta.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use App\Dte\Support\Decimal;

/**
 * Una línea de la venta tal como la manda la caja.
 *
 * En una Factura (01) el precio unitario YA INCLUYE el IVA.
 * Cantidades y precios se reciben como TEXTO ("0.75"), nunca como float.
 */
final readonly class LineaVenta
{
    public Decimal $cantidad;
    public Decimal $precioUnitario;

    // Los descuentos (por ítem y globales) se agregan en un paso posterior,
    // siguiendo la sección XVIII del Manual Funcional v2.0.
    public function __construct(
        public string $descripcion,
        string|int|Decimal $cantidad,
        string|int|Decimal $precioUnitario,
        public int $uniMedida = 59,     // catálogo CAT-014; 59 = Unidad
        public int $tipoItem = 1,       // catálogo CAT-011; 1 = Bienes, 2 = Servicios
        public ?string $codigo = null,  // código interno del producto
    ) {
        $this->cantidad = Decimal::of($cantidad);
        $this->precioUnitario = Decimal::of($precioUnitario);
    }
}
```

### Archivo: `app/Dte/Modelos/Pago.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use App\Dte\Support\Decimal;

/** Una forma de pago (catálogo CAT-017). Si el monto es null, se usa el total a pagar. */
final readonly class Pago
{
    public ?Decimal $monto;

    public function __construct(
        public string $codigo = '01',          // 01 = Billetes y monedas
        string|int|Decimal|null $monto = null,
        public ?string $referencia = null,
        public ?string $plazo = null,          // catálogo CAT-018 (ventas a crédito)
        public ?int $periodo = null,
    ) {
        $this->monto = $monto === null ? null : Decimal::of($monto);
    }
}
```

### Archivo: `app/Dte/Modelos/Receptor.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

/**
 * Cliente que recibe el DTE. Para un consumidor final en ventas menores a
 * $25,000 NO se le puede exigir identificación (aviso de la DGII): en ese caso
 * se usa Receptor::consumidorFinal(), opcionalmente solo con el correo.
 */
final readonly class Receptor
{
    public function __construct(
        public ?string $tipoDocumento = null,   // catálogo CAT-022 (13 DUI, 36 NIT, 03 pasaporte...)
        public ?string $numDocumento = null,
        public ?string $nrc = null,
        public ?string $nombre = null,
        public ?string $codActividad = null,
        public ?string $descActividad = null,
        public ?string $departamento = null,
        public ?string $municipio = null,
        public ?string $distrito = null,
        public ?string $complemento = null,
        public ?string $telefono = null,
        public ?string $correo = null,
    ) {
    }

    public static function consumidorFinal(?string $correo = null): self
    {
        return new self(correo: $correo);
    }

    public function estaIdentificado(): bool
    {
        return $this->tipoDocumento !== null && $this->numDocumento !== null && $this->nombre !== null;
    }

    public function tieneDireccion(): bool
    {
        return $this->departamento !== null && $this->municipio !== null
            && $this->distrito !== null && $this->complemento !== null;
    }
}
```

### Archivo: `app/Dte/Modelos/Venta.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Modelos;

use DateTimeImmutable;

/**
 * La venta completa que el motor convierte en DTE.
 */
final readonly class Venta
{
    /**
     * @param LineaVenta[] $lineas
     * @param Pago[]       $pagos   vacío = un solo pago en efectivo por el total
     */
    public function __construct(
        public array $lineas,
        public Receptor $receptor,
        public int $correlativo,                 // siguiente número de la caja (lo da la base de datos)
        public DateTimeImmutable $fechaHora,     // en zona America/El_Salvador
        public array $pagos = [],
        public int $condicionOperacion = 1,      // catálogo CAT-016; 1 = Contado
        public ?string $observaciones = null,
    ) {
    }
}
```

### Archivo: `app/Dte/Support/CodigoGeneracion.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Support;

/**
 * Código de generación de un DTE: UUID versión 4 en MAYÚSCULAS.
 * Ejemplo: 8F2A6C1E-3B4D-4E5F-9A0B-1C2D3E4F5A6B
 *
 * Regla: se genera uno NUEVO por cada intento de documento. Si Hacienda
 * rechaza un DTE, el documento corregido lleva un código nuevo.
 */
final class CodigoGeneracion
{
    public const PATRON = '/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/';

    public static function nuevo(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // versión 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variante RFC 4122
        $hex = bin2hex($bytes);

        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    public static function esValido(string $codigo): bool
    {
        return (bool) preg_match(self::PATRON, $codigo);
    }
}
```

### Archivo: `app/Dte/Support/Decimal.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Support;

use InvalidArgumentException;

/**
 * Número decimal EXACTO, sin errores de punto flotante.
 *
 * Regla de oro del motor: todo monto se calcula con Decimal, nunca con float.
 *   - En PHP, 0.1 + 0.2 con float da 0.30000000000000004.
 *   - Con Decimal, Decimal::of('0.1')->add(Decimal::of('0.2')) da exactamente 0.3.
 *
 * Internamente guarda un entero sin límite de tamaño (como texto) y una escala
 * (cuántos dígitos son decimales). No necesita extensiones (ni bcmath ni gmp).
 *
 * Redondeo: HALF_UP, como lo define el Manual Funcional v2.0 de Hacienda
 * ("si la primera posición fuera de rango es 5 o mayor, se eleva la última").
 * Para negativos se redondea alejándose de cero (-5.665 → -5.67).
 */
final class Decimal
{
    /** @param string $digits valor absoluto sin escala, solo dígitos, sin ceros a la izquierda */
    private function __construct(
        private readonly bool $negative,
        private readonly string $digits,
        private readonly int $scale,
    ) {
    }

    // ------------------------------------------------------------------
    // Creación
    // ------------------------------------------------------------------

    /**
     * Crea un Decimal desde texto o entero. NO acepta float a propósito:
     * un float ya trae el error de precisión antes de llegar aquí.
     */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }

        $value = trim($value);
        if (!preg_match('/^([+-])?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Valor decimal inválido: '{$value}'");
        }

        $negative = ($m[1] ?? '') === '-';
        $int = $m[2];
        $frac = $m[3] ?? '';

        return self::make($negative, $int . $frac, strlen($frac));
    }

    public static function zero(): self
    {
        return new self(false, '0', 0);
    }

    /** Suma una lista de Decimal (o valores convertibles). */
    public static function sum(iterable $values): self
    {
        $total = self::zero();
        foreach ($values as $v) {
            $total = $total->add(self::of($v));
        }

        return $total;
    }

    private static function make(bool $negative, string $digits, int $scale): self
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
            $negative = false; // no existe "-0"
        }

        return new self($negative, $digits, $scale);
    }

    // ------------------------------------------------------------------
    // Operaciones
    // ------------------------------------------------------------------

    public function add(string|int|self $other): self
    {
        $o = self::of($other);
        $scale = max($this->scale, $o->scale);
        $a = self::shift($this->digits, $scale - $this->scale);
        $b = self::shift($o->digits, $scale - $o->scale);

        if ($this->negative === $o->negative) {
            return self::make($this->negative, self::addAbs($a, $b), $scale);
        }

        // Signos distintos: se resta el menor del mayor.
        $cmp = self::cmpAbs($a, $b);
        if ($cmp === 0) {
            return self::make(false, '0', $scale);
        }
        if ($cmp > 0) {
            return self::make($this->negative, self::subAbs($a, $b), $scale);
        }

        return self::make($o->negative, self::subAbs($b, $a), $scale);
    }

    public function sub(string|int|self $other): self
    {
        return $this->add(self::of($other)->negate());
    }

    public function mul(string|int|self $other): self
    {
        $o = self::of($other);

        return self::make(
            $this->negative !== $o->negative,
            self::mulAbs($this->digits, $o->digits),
            $this->scale + $o->scale,
        );
    }

    /**
     * División redondeada a $scale decimales con HALF_UP.
     * Ejemplo: Decimal::of('6.50')->mul('13')->div('113', 8) = 0.74778761
     */
    public function div(string|int|self $other, int $scale): self
    {
        $o = self::of($other);
        if ($o->isZero()) {
            throw new InvalidArgumentException('División entre cero');
        }
        if ($scale < 0) {
            throw new InvalidArgumentException('La escala no puede ser negativa');
        }

        // Se calcula un dígito extra (scale + 1) y luego se redondea.
        // Para HALF_UP basta con conocer el primer dígito descartado.
        $k = ($scale + 1) + $o->scale - $this->scale;
        $num = $this->digits;
        $den = $o->digits;
        if ($k >= 0) {
            $num = self::shift($num, $k);
        } else {
            $den = self::shift($den, -$k);
        }

        $q = self::divAbs($num, $den);

        return self::make($this->negative !== $o->negative, $q, $scale + 1)->round($scale);
    }

    /** Redondeo HALF_UP a $scale decimales. */
    public function round(int $scale): self
    {
        if ($scale >= $this->scale) {
            return new self($this->negative, $this->digits, $this->scale);
        }

        $drop = $this->scale - $scale;
        $padded = str_pad($this->digits, $drop + 1, '0', STR_PAD_LEFT);
        $kept = substr($padded, 0, -$drop);
        $firstDropped = (int) $padded[strlen($padded) - $drop];

        if ($firstDropped >= 5) {
            $kept = self::addAbs($kept, '1');
        }

        return self::make($this->negative, $kept, $scale);
    }

    public function negate(): self
    {
        return self::make(!$this->negative, $this->digits, $this->scale);
    }

    public function abs(): self
    {
        return self::make(false, $this->digits, $this->scale);
    }

    // ------------------------------------------------------------------
    // Comparaciones
    // ------------------------------------------------------------------

    /** -1 si es menor, 0 si es igual, 1 si es mayor. */
    public function compareTo(string|int|self $other): int
    {
        $diff = $this->sub($other);
        if ($diff->isZero()) {
            return 0;
        }

        return $diff->negative ? -1 : 1;
    }

    public function equals(string|int|self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function isNegative(): bool
    {
        return $this->negative;
    }

    public function isPositive(): bool
    {
        return !$this->negative && !$this->isZero();
    }

    public function scale(): int
    {
        return $this->scale;
    }

    // ------------------------------------------------------------------
    // Salida
    // ------------------------------------------------------------------

    /** Texto con exactamente la escala actual, por ejemplo "6.50". */
    public function __toString(): string
    {
        $padded = str_pad($this->digits, $this->scale + 1, '0', STR_PAD_LEFT);
        $int = $this->scale > 0 ? substr($padded, 0, -$this->scale) : $padded;
        $frac = $this->scale > 0 ? substr($padded, -$this->scale) : '';

        return ($this->negative ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** Texto con un número fijo de decimales (redondeando si hace falta). */
    public function toFixed(int $scale): string
    {
        $r = $this->round($scale);
        if ($r->scale < $scale) {
            $r = new self($r->negative, self::shift($r->digits, $scale - $r->scale), $scale);
        }

        return (string) $r;
    }

    /**
     * Texto sin ceros sobrantes al final: "6.50" → "6.5", "11.00" → "11".
     * Es lo que se escribe como número en el JSON del DTE.
     */
    public function toPlainString(): string
    {
        $s = (string) $this;
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    // ------------------------------------------------------------------
    // Aritmética de enteros grandes en texto (valores absolutos)
    // ------------------------------------------------------------------

    private static function shift(string $digits, int $places): string
    {
        return $places > 0 && $digits !== '0' ? $digits . str_repeat('0', $places) : $digits;
    }

    private static function cmpAbs(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function addAbs(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i--] : 0) + ($j >= 0 ? (int) $b[$j--] : 0);
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    /** Requiere $a >= $b. */
    private static function subAbs(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - $borrow - ($j >= 0 ? (int) $b[$j--] : 0);
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function mulAbs(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int) $a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $p = $da * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }

        return ltrim(implode('', $res), '0') ?: '0';
    }

    /** División entera (se descarta el residuo). */
    private static function divAbs(string $num, string $den): string
    {
        $quotient = '';
        $rem = '0';
        $len = strlen($num);
        for ($i = 0; $i < $len; $i++) {
            $rem = ltrim($rem . $num[$i], '0') ?: '0';
            $digit = 0;
            while (self::cmpAbs($rem, $den) >= 0) {
                $rem = self::subAbs($rem, $den);
                $digit++;
            }
            $quotient .= $digit;
        }

        return ltrim($quotient, '0') ?: '0';
    }
}
```

### Archivo: `app/Dte/Support/DteJson.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Support;

use JsonException;

/**
 * Convierte el arreglo del DTE en texto JSON escribiendo cada Decimal como
 * número JSON EXACTO (sin pasar nunca por float).
 *
 *   ['totalPagar' => Decimal::of('6.50')]  →  {"totalPagar":6.5}
 *
 * También cumple dos reglas del Manual Tecnológico v2.0 (sección 6.1):
 *   - todo en UTF-8 (tildes y eñes sin escapar),
 *   - JSON limpio, sin caracteres sobrantes.
 */
final class DteJson
{
    private const MARCA = '__DEC__';

    /** @throws JsonException */
    public static function encode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode(self::marcar($data), $flags);

        return preg_replace('/"' . self::MARCA . '(-?\d+(?:\.\d+)?)' . self::MARCA . '"/', '$1', $json);
    }

    private static function marcar(mixed $value): mixed
    {
        if ($value instanceof Decimal) {
            return self::MARCA . $value->toPlainString() . self::MARCA;
        }
        if (is_float($value)) {
            throw new \LogicException('No se permiten float en un DTE: use Decimal.');
        }
        if (is_array($value)) {
            return array_map([self::class, 'marcar'], $value);
        }

        return $value;
    }
}
```

### Archivo: `app/Dte/Support/NumeroALetras.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Support;

use InvalidArgumentException;

/**
 * Convierte un monto a letras para el campo resumen.totalLetras.
 *
 * Sigue el estilo del ejemplo oficial del Manual Funcional v2.0
 * ("Cuatrocientos cincuenta y dos con 47/100 dólares"), en mayúsculas:
 *   6.50    → "SEIS CON 50/100 DÓLARES"
 *   1130.00 → "UN MIL CIENTO TREINTA CON 00/100 DÓLARES"
 */
final class NumeroALetras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    private const CENTENAS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    public static function convertir(Decimal|string $monto): string
    {
        $monto = Decimal::of($monto);
        if ($monto->isNegative()) {
            throw new InvalidArgumentException('El monto en letras no puede ser negativo');
        }

        [$entero, $centavos] = explode('.', $monto->toFixed(2));
        $entero = ltrim($entero, '0') ?: '0';
        if (strlen($entero) > 12) {
            throw new InvalidArgumentException('Monto demasiado grande para convertir a letras');
        }

        $letras = $entero === '0' ? 'CERO' : self::enteroALetras((int) $entero);

        return "{$letras} CON {$centavos}/100 DÓLARES";
    }

    private static function enteroALetras(int $n): string
    {
        $partes = [];

        $millones = intdiv($n, 1_000_000);
        $resto = $n % 1_000_000;
        if ($millones > 0) {
            $partes[] = $millones === 1
                ? 'UN MILLÓN'
                : self::apocopar(self::hastaMil($millones)) . ' MILLONES';
        }

        $miles = intdiv($resto, 1000);
        $unidades = $resto % 1000;
        if ($miles > 0) {
            // Estilo usual en documentos comerciales salvadoreños: "UN MIL".
            $partes[] = self::apocopar(self::hastaMil($miles)) . ' MIL';
        }
        if ($unidades > 0) {
            $partes[] = self::hastaMil($unidades);
        }

        return implode(' ', $partes);
    }

    /** 1 a 999. */
    private static function hastaMil(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $texto = $c > 0 ? self::CENTENAS[$c] : '';

        if ($r > 0) {
            $texto .= ($texto !== '' ? ' ' : '') . self::hastaCien($r);
        }

        return $texto;
    }

    /** 1 a 99. */
    private static function hastaCien(int $n): string
    {
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;

        return self::DECENAS[$d] . ($u > 0 ? ' Y ' . self::UNIDADES[$u] : '');
    }

    /** "UNO" → "UN" y "VEINTIUNO" → "VEINTIÚN" delante de MIL / MILLONES. */
    private static function apocopar(string $texto): string
    {
        if (str_ends_with($texto, 'VEINTIUNO')) {
            return substr($texto, 0, -strlen('VEINTIUNO')) . 'VEINTIÚN';
        }
        if (str_ends_with($texto, 'UNO')) {
            return substr($texto, 0, -3) . 'UN';
        }

        return $texto;
    }
}
```

### Archivo: `app/Dte/Support/NumeroControl.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Support;

use InvalidArgumentException;

/**
 * Número de control del DTE (Manual Funcional v2.0, sección XI).
 *
 *   DTE-01-M001P001-000000000000001
 *   │   │  │   │    └ correlativo de 15 dígitos (no se repite en el año calendario)
 *   │   │  │   └ punto de venta: "P" + 3 dígitos
 *   │   │  └ establecimiento: "M", "B", "S" o "P" + 3 dígitos
 *   │   └ tipo de DTE (catálogo CAT-002)
 *   └ siempre "DTE"
 *
 * Total: 31 caracteres contando los guiones.
 */
final class NumeroControl
{
    public static function generar(string $tipoDte, string $codEstable, string $codPuntoVenta, int $correlativo): string
    {
        if (!preg_match('/^\d{2}$/', $tipoDte)) {
            throw new InvalidArgumentException("Tipo de DTE inválido: {$tipoDte}");
        }
        if (!preg_match('/^[MBSP]\d{3}$/', $codEstable)) {
            throw new InvalidArgumentException("Código de establecimiento inválido (ejemplo correcto: M001): {$codEstable}");
        }
        if (!preg_match('/^P\d{3}$/', $codPuntoVenta)) {
            throw new InvalidArgumentException("Código de punto de venta inválido (ejemplo correcto: P001): {$codPuntoVenta}");
        }
        if ($correlativo < 1 || $correlativo > 999_999_999_999_999) {
            throw new InvalidArgumentException("Correlativo fuera de rango: {$correlativo}");
        }

        return sprintf('DTE-%s-%s%s-%015d', $tipoDte, $codEstable, $codPuntoVenta, $correlativo);
    }

    public static function esValido(string $numero, ?string $tipoDte = null): bool
    {
        $tipo = $tipoDte !== null ? preg_quote($tipoDte, '/') : '\d{2}';

        return strlen($numero) === 31
            && (bool) preg_match('/^DTE-' . $tipo . '-[MBSP]\d{3}P\d{3}-\d{15}$/', $numero);
    }
}
```

### Archivo: `app/Dte/Validacion/ValidadorEsquema.php`

```php
<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Revisa un DTE contra el JSON Schema oficial de Hacienda ANTES de firmarlo
 * y enviarlo. Si algo falla aquí, se corrige sin gastar un envío a Hacienda.
 *
 * Requiere: composer require opis/json-schema
 * Los esquemas oficiales se descargan de factura.gob.sv → Documentos Técnicos
 * → "Json Schemas" y se guardan en storage/app/dte/schemas/.
 *
 * Importante: cumplir el esquema NO garantiza que los cálculos fiscales estén
 * bien (Manual Tecnológico v2.0, sección 6.1). Esos los asegura el builder.
 */
final class ValidadorEsquema
{
    private const ESQUEMAS = [
        '01' => 'fe-f-v2.json',
        // Se agregan al implementar cada tipo:
        // '03' => 'fe-ccf-v4.json', '05' => 'fe-nc-v4.json', '06' => 'fe-nd-v4.json',
        // '11' => 'fe-fex-v3.json', '14' => 'fe-fse-v2.json',
    ];

    public function __construct(private readonly string $carpetaEsquemas)
    {
    }

    /**
     * @return array<string, string[]> errores por ruta del campo; vacío = válido
     */
    public function validar(string $json, string $tipoDte): array
    {
        $archivo = self::ESQUEMAS[$tipoDte] ?? null;
        if ($archivo === null) {
            throw new RuntimeException("No hay esquema configurado para el tipo de DTE {$tipoDte}.");
        }

        $ruta = rtrim($this->carpetaEsquemas, '/') . '/' . $archivo;
        if (!is_file($ruta)) {
            throw new RuntimeException("No se encontró el esquema oficial: {$ruta}");
        }

        $validator = new Validator();
        $validator->setMaxErrors(50);
        $resultado = $validator->validate(
            json_decode($json, flags: JSON_THROW_ON_ERROR),
            file_get_contents($ruta),
        );

        if ($resultado->isValid()) {
            return [];
        }

        return (new ErrorFormatter())->format($resultado->error());
    }
}
```

### Archivo: `storage/app/dte/schemas/fe-f-v2.json`

```json
{"$schema":"http://json-schema.org/draft-07/schema#","title":"Factura Electrónica","type":"object","properties":{"identificacion":{"description":"Identificación","type":"object","properties":{"version":{"type":"integer","description":"Versión","const":2},"ambiente":{"type":"string","description":"Ambiente de destino","enum":["00","01"]},"tipoDte":{"type":"string","description":"Tipo de Documento","const":"01"},"numeroControl":{"type":"string","description":"Número de Control","minLength":31,"maxLength":31,"pattern":"^DTE-01-(M|B|S|P)([0-9]{3})(P)([0-9]{3})-[0-9]{15}"},"codigoGeneracion":{"type":"string","description":"Código de Generación","minLength":36,"maxLength":36,"pattern":"^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$"},"tipoModelo":{"type":"number","description":"Modelo de Facturación","enum":[1,2]},"tipoOperacion":{"type":"integer","description":"Tipo de Transmisión","enum":[1,2]},"tipoContingencia":{"description":"Tipo de Contingencia","type":["integer","null"],"enum":[null,1,2,3,4,5]},"motivoContin":{"description":"Motivo de Contingencia","type":["string","null"],"minLength":1,"maxLength":500},"fecEmi":{"type":"string","description":"Fecha de Generación","format":"date"},"horEmi":{"type":"string","description":"Hora de Generación","pattern":"^(?!.*[\r\n])(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]?$"},"tipoMoneda":{"type":"string","description":"Tipo de Moneda","enum":["USD"]}},"additionalProperties":false,"required":["version","ambiente","tipoDte","numeroControl","codigoGeneracion","tipoModelo","tipoOperacion","fecEmi","horEmi","tipoMoneda","tipoContingencia","motivoContin"]},"documentoRelacionado":{"description":"Documentos Relacionados","type":["array","null"],"items":{"type":"object","properties":{"tipoDocumento":{"type":"string","description":"Tipo de Documento Relacionado"},"tipoGeneracion":{"type":"integer","description":"Tipo de Generación del Documento Relacionado"},"numeroDocumento":{"description":"Número de documento relacionado","type":"string","minLength":1,"maxLength":36},"fechaEmision":{"description":"Fecha de Generación del Documento Relacionado","type":"string","format":"date"}},"additionalProperties":false,"required":["tipoDocumento","tipoGeneracion","numeroDocumento","fechaEmision"]},"minItems":1,"maxItems":50},"emisor":{"type":"object","description":"Emisor","properties":{"nit":{"description":"NIT (Emisor)","type":"string","maxLength":14},"nrc":{"description":"NRC (Emisor)","type":"string","minLength":2,"maxLength":8},"nombre":{"type":"string","description":"Nombre (Emisor)","minLength":1,"maxLength":250},"codActividad":{"type":"string","description":"Código de Actividad Económica (Emisor)","minLength":5,"maxLength":6},"descActividad":{"type":"string","description":"Actividad Económica (Emisor)","minLength":5,"maxLength":150},"nombreComercial":{"description":"Nombre Comercial (Emisor)","type":["string","null"],"minLength":1,"maxLength":150},"direccion":{"type":"object","description":"Dirección (Emisor)","properties":{"departamento":{"type":"string","description":"Dirección Departamento (Emisor)"},"municipio":{"type":"string","description":"Dirección Municipio (Emisor)"},"distrito":{"type":"string","description":"Dirección Distrito (Emisor)"},"complemento":{"type":"string","description":"Dirección complemento (Emisor)","minLength":1,"maxLength":200}},"additionalProperties":false,"required":["departamento","municipio","distrito","complemento"]},"telefono":{"type":"string","description":"Teléfono (Emisor)","minLength":8,"maxLength":30},"correo":{"type":"string","description":"Correo electrónico (Emisor)","minLength":6,"maxLength":100},"codEstable":{"description":"Código del establecimiento asignado por el contribuyente","type":["string","null"],"minLength":4,"maxLength":4},"codPuntoVenta":{"description":"Código del Punto de Venta (Emisor) asignado por el contribuyente","type":["string","null"],"minLength":1,"maxLength":15}},"additionalProperties":false,"required":["nit","nrc","nombre","codActividad","descActividad","nombreComercial","direccion","telefono","correo","codEstable","codPuntoVenta"]},"receptor":{"type":["object","null"],"description":"Receptor","properties":{"tipoDocumento":{"type":["null","string"],"description":"Tipo de documento de identificación (Receptor)"},"numDocumento":{"type":["string","null"],"description":"Número de documento de Identificación (Receptor)","minLength":1,"maxLength":20},"nrc":{"type":["string","null"],"description":"NRC (Receptor)","minLength":2,"maxLength":8},"nombre":{"type":["string","null"],"description":"Nombre (Receptor)","minLength":1,"maxLength":250},"codActividad":{"type":["string","null"],"description":"Código de Actividad Económica (Receptor)","minLength":5,"maxLength":6},"descActividad":{"type":["string","null"],"description":"Actividad Económica (Receptor)","minLength":5,"maxLength":150},"direccion":{"type":["object","null"],"description":"Dirección (Receptor)","properties":{"departamento":{"type":"string","description":"Dirección: Departamento (Receptor)"},"municipio":{"type":"string","description":"Dirección: Municipio (Receptor)"},"distrito":{"type":"string","description":"Dirección Distrito (Receptor)"},"complemento":{"type":"string","description":"Dirección: complemento (Receptor)","minLength":1,"maxLength":200}},"additionalProperties":false,"required":["departamento","municipio","distrito","complemento"]},"telefono":{"type":["string","null"],"description":"Teléfono (Receptor)","minLength":8,"maxLength":30},"correo":{"type":["string","null"],"description":"Correo electrónico (Receptor)","minLength":6,"maxLength":100}},"additionalProperties":false,"required":["tipoDocumento","numDocumento","nrc","nombre","codActividad","descActividad","direccion","telefono","correo"]},"otrosDocumentos":{"description":"Documentos Asociados","type":["array","null"],"items":{"type":"object","properties":{"codDocAsociado":{"type":["integer","null"],"description":"Documento asociado","minimum":1,"maximum":4},"descDocumento":{"type":["string","null"],"description":"Identificación del documento asociado","minimum":3,"maxLength":100},"detalleDocumento":{"type":["string","null"],"description":"Descripción de documento asociado","minimum":3,"maxLength":300},"medico":{"description":"Médico","type":["object","null"],"properties":{"nombre":{"type":["string","null"],"description":"Nombre de médico que presta el Servicio","minimum":1,"maxLength":100},"nit":{"type":["string","null"],"description":"NIT de médico que presta el Servicio"},"docIdentificacion":{"type":["string","null"],"description":"Documento de identificación de médico no domiciliados","minLength":1,"maxLength":25},"tipoServicio":{"type":["number","null"],"description":"Código del Servicio realizado","minimum":1,"maximum":6}},"additionalProperties":false,"required":["nit","nombre","docIdentificacion","tipoServicio"]}},"additionalProperties":false,"required":["codDocAsociado","descDocumento","detalleDocumento","medico"]},"minItems":1,"maxItems":10},"ventaTercero":{"description":"Ventas por cuenta de terceros","type":["object","null"],"properties":{"nit":{"type":["string","null"],"description":"NIT por cuenta de Terceros","minLength":1,"maxLength":20},"nombre":{"type":"string","description":"Nombre del Tercero","minLength":1,"maxLength":250},"codDomiciliado":{"type":["integer","null"],"description":"Domicilio Fiscal"}},"additionalProperties":false,"required":["nit","nombre","codDomiciliado"]},"cuerpoDocumento":{"type":"array","description":"Cuerpo del Documento","items":{"type":"object","properties":{"numItem":{"type":"integer","description":"N° de ítem","minimum":1,"maximum":2000},"tipoItem":{"type":"integer","description":"Tipo de ítem"},"numeroDocumento":{"description":"Número de documento relacionado","type":["string","null"],"minLength":1,"maxLength":36},"cantidad":{"type":"number","description":"Cantidad","exclusiveMaximum":100000000000,"exclusiveMinimum":0,"multipleOf":1e-8},"codigo":{"description":"Código","type":["string","null"],"minLength":1,"maxLength":25},"codTributo":{"description":"Tributo sujeto a cálculo de IVA","type":["string","null"],"minLength":2,"maxLength":2},"uniMedida":{"type":"integer","description":"Unidad de Medida","minimum":1,"maximum":99},"descripcion":{"type":"string","description":"Descripción","minLength":1,"maxLength":1500},"precioUni":{"type":"number","description":"Precio Unitario","exclusiveMaximum":100000000000,"multipleOf":1e-8},"montoDescu":{"type":"number","description":"Descuento por ítem","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8},"ventaNoSuj":{"type":"number","description":"Ventas no Sujetas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8},"ventaExenta":{"type":"number","description":"Ventas Exentas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8},"ventaGravada":{"type":"number","description":"Ventas Gravadas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8},"tributos":{"description":"Código del Tributo","type":["array","null"],"items":{"type":"string","minLength":2,"maxLength":2},"minItems":1,"uniqueItems":true},"psv":{"type":"number","description":"Precio sugerido de venta","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8},"noGravado":{"type":"number","description":"Cargos/Abonos que no afectan la base imponible","exclusiveMaximum":100000000000,"exclusiveMinimum":-100000000000,"multipleOf":1e-8},"ivaItem":{"type":"number","description":"IVA por ítem","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":1e-8}},"additionalProperties":false,"required":["numItem","tipoItem","numeroDocumento","codigo","codTributo","descripcion","cantidad","uniMedida","precioUni","montoDescu","ventaNoSuj","ventaExenta","ventaGravada","tributos","psv","noGravado","ivaItem"]},"minItems":1,"maxItems":2000},"resumen":{"type":"object","description":"Resumen","properties":{"totalNoSuj":{"type":"number","description":"Total de Operaciones no sujetas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"totalExenta":{"type":"number","description":"Total de Operaciones exentas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"totalGravada":{"type":"number","description":"Total de Operaciones Gravadas","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"subTotalVentas":{"type":"number","description":"Suma de operaciones","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"descuNoSuj":{"type":"number","description":"Descuento global a ventas no sujetas","exclusiveMaximum":100000000000,"minimum":0,"multipleOf":0.01},"descuExenta":{"type":"number","description":"Descuento global a ventas exentas","exclusiveMaximum":100000000000,"minimum":0,"multipleOf":0.01},"descuGravada":{"type":"number","description":"Descuento global a ventas gravadas","exclusiveMaximum":100000000000,"minimum":0,"multipleOf":0.01},"porcentajeDescuento":{"type":"number","description":"Porcentaje de Descuento","maximum":100,"minimum":0,"multipleOf":0.01},"totalDescu":{"type":"number","description":"Total de Descuentos","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"tributos":{"type":["array","null"],"uniqueItems":true,"description":"Resumen de Tributos","items":{"type":"object","properties":{"codigo":{"description":"Resumen Código de Tributo","type":"string","minLength":2,"maxLength":2},"descripcion":{"description":"Nombre del Tributo","type":"string","minLength":1,"maxLength":300},"valor":{"description":"Valor del Tributo","type":"number","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01}},"additionalProperties":false,"required":["codigo","descripcion","valor"]}},"subTotal":{"type":"number","description":"Sub-Total","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"ivaRete":{"type":"number","description":"IVA Retenido","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"montoTotalOperacion":{"type":"number","description":"Monto Total de la Operación","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"totalNoGravado":{"type":"number","description":"Total Cargos/Abonos que no afectan la base imponible","exclusiveMaximum":100000000000,"exclusiveMinimum":-100000000000,"multipleOf":0.01},"totalPagar":{"type":"number","description":"Total a Pagar","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"totalLetras":{"type":["string","null"],"description":"Valor en Condicion de la Operacion","minLength":1,"maxLength":200},"totalIva":{"type":"number","description":"IVA 13%","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"saldoFavor":{"type":"number","description":"Saldo a Favor","maximum":0,"exclusiveMinimum":-100000000000,"multipleOf":0.01},"condicionOperacion":{"type":"number","description":"Condición de la Operación"},"pagos":{"description":"Pagos","type":["array","null"],"items":{"type":"object","properties":{"codigo":{"type":["string","null"],"description":"Código de forma de pago","maxLength":2},"montoPago":{"type":"number","description":"Monto por forma de pago","minimum":0,"exclusiveMaximum":100000000000,"multipleOf":0.01},"referencia":{"type":["string","null"],"description":"Referencia de modalidad de pago","maxLength":50},"plazo":{"description":"Plazo","type":["string","null"]},"periodo":{"description":"Período de plazo","type":["number","null"],"exclusiveMinimum":0}},"additionalProperties":false,"required":["codigo","montoPago","referencia","periodo","plazo"]},"minItems":1},"numPagoElectronico":{"description":"Número de pago Electrónico","type":["string","null"],"maxLength":100},"observaciones":{"type":["string","null"],"description":"Observaciones","minLength":1,"maxLength":3000}},"additionalProperties":false,"required":["totalNoSuj","totalExenta","totalGravada","subTotalVentas","descuNoSuj","descuExenta","descuGravada","porcentajeDescuento","totalDescu","tributos","subTotal","ivaRete","montoTotalOperacion","totalNoGravado","totalPagar","totalLetras","totalIva","saldoFavor","condicionOperacion","pagos","numPagoElectronico","observaciones"]},"apendice":{"description":"Apéndice","type":["array","null"],"items":{"type":"object","properties":{"campo":{"type":"string","description":"Nombre del campo","maxLength":25},"etiqueta":{"type":"string","description":"Descripción","maxLength":50},"valor":{"type":"string","description":"Valor/Dato","maxLength":150}},"additionalProperties":false,"required":["campo","etiqueta","valor"]},"minItems":1,"maxItems":10}},"additionalProperties":false,"required":["identificacion","documentoRelacionado","emisor","receptor","otrosDocumentos","ventaTercero","cuerpoDocumento","resumen","apendice"]}
```

### Archivo: `tests/Unit/Dte/DecimalTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Support\Decimal;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_suma_exacta_sin_errores_de_float(): void
    {
        // Con float: 0.1 + 0.2 = 0.30000000000000004
        $this->assertSame('0.3', (string) Decimal::of('0.1')->add('0.2'));
        $this->assertTrue(Decimal::of('0.1')->add('0.2')->equals('0.3'));
    }

    public function test_redondeo_del_resumen_segun_manual_funcional(): void
    {
        // Ejemplos oficiales del Manual Funcional v2.0, sección XXI.
        $this->assertSame('5.66', Decimal::of('5.664')->toFixed(2));
        $this->assertSame('5.67', Decimal::of('5.665')->toFixed(2));
        $this->assertSame('5.67', Decimal::of('5.666')->toFixed(2));
    }

    public function test_redondeo_a_ocho_decimales_segun_manual_funcional(): void
    {
        // Cantidad 1.87654321 9 87654 → 1.87654322 ; Precio 3.55555555 4 → 3.55555555
        $this->assertSame('1.87654322', Decimal::of('1.87654321987654')->toFixed(8));
        $this->assertSame('3.55555555', Decimal::of('3.555555554')->toFixed(8));
        $this->assertSame('5.67215367', Decimal::of('5.67215366775307')->toFixed(8));
    }

    public function test_redondeo_de_negativos_se_aleja_de_cero(): void
    {
        $this->assertSame('-5.67', Decimal::of('-5.665')->toFixed(2));
        $this->assertSame('-5.66', Decimal::of('-5.664')->toFixed(2));
    }

    public function test_acarreo_al_redondear(): void
    {
        $this->assertSame('1.00', Decimal::of('0.995')->toFixed(2));
        $this->assertSame('10.00', Decimal::of('9.999')->toFixed(2));
    }

    public function test_multiplicacion_y_division(): void
    {
        $this->assertSame('4.50', (string) Decimal::of('6')->mul('0.75'));
        // IVA contenido en $6.50: 6.50 × 13 / 113 = 0.747787610...
        $this->assertSame('0.74778761', (string) Decimal::of('6.50')->mul(13)->div(113, 8));
        $this->assertSame('0.75', (string) Decimal::of('6.50')->mul(13)->div(113, 2));
        $this->assertSame('-0.5', (string) Decimal::of('1')->div('-2', 1));
    }

    public function test_numeros_grandes_sin_desbordamiento(): void
    {
        // (10^11 − 10^-8)² = 10^22 − 2000 + 10^-16 : con float esto se pierde por completo.
        $a = Decimal::of('99999999999.99999999');
        $this->assertSame('9999999999999999998000.0000000000000001', (string) $a->mul($a));
    }

    public function test_resta_y_comparacion(): void
    {
        $this->assertSame('-1.25', (string) Decimal::of('1.00')->sub('2.25'));
        $this->assertSame(1, Decimal::of('2')->compareTo('1.999'));
        $this->assertSame(-1, Decimal::of('-3')->compareTo('0'));
        $this->assertSame(0, Decimal::of('1.50')->compareTo('1.5'));
    }

    public function test_texto_sin_ceros_sobrantes_para_el_json(): void
    {
        $this->assertSame('6.5', Decimal::of('6.50')->toPlainString());
        $this->assertSame('11', Decimal::of('11.00')->toPlainString());
        $this->assertSame('0', Decimal::of('0.000')->toPlainString());
        $this->assertSame('0.2300885', Decimal::of('0.23008850')->toPlainString());
    }

    public function test_no_acepta_float(): void
    {
        $this->expectException(\TypeError::class);
        Decimal::of(0.1); // @phpstan-ignore-line
    }

    public function test_no_acepta_texto_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('1,50');
    }
}
```

### Archivo: `tests/Unit/Dte/FacturaBuilderTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Excepciones\DteException;
use App\Dte\Factura\FacturaBuilder;
use App\Dte\Modelos\Emisor;
use App\Dte\Modelos\LineaVenta;
use App\Dte\Modelos\Pago;
use App\Dte\Modelos\Receptor;
use App\Dte\Modelos\Venta;
use App\Dte\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FacturaBuilderTest extends TestCase
{
    private const CODIGO = '8F2A6C1E-3B4D-4E5F-9A0B-1C2D3E4F5A6B';

    private function emisor(): Emisor
    {
        return new Emisor(
            nit: '06140000000000',
            nrc: '1234567',
            nombre: 'PUPUSERIA LA ESQUINA S.A. DE C.V.',
            codActividad: '56101',
            descActividad: 'Restaurantes',
            nombreComercial: 'Pupusería La Esquina',
            departamento: '06',
            municipio: '23',
            distrito: '14',
            complemento: 'Calle Principal 123',
            telefono: '22220000',
            correo: 'ventas@laesquina.com',
            codEstable: 'M001',
            codPuntoVenta: 'P001',
        );
    }

    private function venta(array $lineas, ?Receptor $receptor = null, array $pagos = []): Venta
    {
        return new Venta(
            lineas: $lineas,
            receptor: $receptor ?? Receptor::consumidorFinal(),
            correlativo: 1,
            fechaHora: new DateTimeImmutable('2026-11-10 15:30:00', new DateTimeZone('America/El_Salvador')),
            pagos: $pagos,
        );
    }

    private function construir(Venta $venta): array
    {
        $r = (new FacturaBuilder($this->emisor(), '00'))->construir($venta, self::CODIGO);

        return [$r, $r->documento()];
    }

    public function test_pupuseria_del_curso(): void
    {
        [$r, $doc] = $this->construir($this->venta([
            new LineaVenta('Pupusa revuelta', '6', '0.75'),
            new LineaVenta('Horchata', '2', '1.00'),
        ]));

        $this->assertSame('6.50', $r->totalPagar->toFixed(2));
        $this->assertSame('0.75', $r->totalIva->toFixed(2));
        $this->assertSame('DTE-01-M001P001-000000000000001', $r->numeroControl);

        // Las líneas: 4.50 y 2.00 con IVA contenido a 8 decimales.
        $this->assertStringContainsString('"ventaGravada":4.5,', $r->json);
        $this->assertStringContainsString('"ivaItem":0.51769912}', $r->json);
        $this->assertStringContainsString('"ivaItem":0.2300885}', $r->json);

        // El resumen.
        $this->assertStringContainsString('"totalGravada":6.5,', $r->json);
        $this->assertStringContainsString('"totalIva":0.75,', $r->json);
        $this->assertStringContainsString('"totalPagar":6.5,', $r->json);
        $this->assertSame('SEIS CON 50/100 DÓLARES', $doc['resumen']['totalLetras']);
        $this->assertSame('01', $doc['resumen']['pagos'][0]['codigo']);

        // Identificación.
        $this->assertSame(2, $doc['identificacion']['version']);
        $this->assertSame('2026-11-10', $doc['identificacion']['fecEmi']);
        $this->assertSame('15:30:00', $doc['identificacion']['horEmi']);
        $this->assertSame(self::CODIGO, $doc['identificacion']['codigoGeneracion']);

        // Consumidor final: receptor con todos sus campos en null.
        $this->assertNull($doc['receptor']['nombre']);
        $this->assertNull($doc['receptor']['direccion']);
    }

    public function test_todas_las_secciones_del_esquema_estan_presentes(): void
    {
        [, $doc] = $this->construir($this->venta([new LineaVenta('Café', '1', '0.85')]));

        foreach (['identificacion', 'documentoRelacionado', 'emisor', 'receptor', 'otrosDocumentos',
                     'ventaTercero', 'cuerpoDocumento', 'resumen', 'apendice'] as $seccion) {
            $this->assertArrayHasKey($seccion, $doc);
        }
    }

    public function test_hora_se_convierte_a_hora_de_el_salvador(): void
    {
        $venta = new Venta(
            lineas: [new LineaVenta('Café', '1', '0.85')],
            receptor: Receptor::consumidorFinal(),
            correlativo: 7,
            fechaHora: new DateTimeImmutable('2026-11-10 21:30:00', new DateTimeZone('UTC')),
        );
        [, $doc] = $this->construir($venta);

        $this->assertSame('2026-11-10', $doc['identificacion']['fecEmi']);
        $this->assertSame('15:30:00', $doc['identificacion']['horEmi']); // UTC-6
    }

    public function test_muchas_lineas_cuadran_dentro_de_la_holgura(): void
    {
        $lineas = [];
        for ($i = 1; $i <= 37; $i++) {
            $lineas[] = new LineaVenta("Producto {$i}", (string) $i, '1.13');
        }
        [$r] = $this->construir($this->venta($lineas));

        // Total = 1.13 × (1 + 2 + ... + 37) = 1.13 × 703 = 794.39
        $this->assertSame('794.39', $r->totalPagar->toFixed(2));
        // IVA contenido = 794.39 × 13 / 113 = 91.39
        $this->assertSame('91.39', $r->totalIva->toFixed(2));
    }

    public function test_cantidades_con_decimales(): void
    {
        // 2.5 libras de queso a $3.99 la libra = 9.975 → 9.975 a 8 decimales; total 9.98
        [$r, $doc] = $this->construir($this->venta([new LineaVenta('Queso duro', '2.5', '3.99', uniMedida: 36)]));

        $this->assertSame('9.98', $r->totalPagar->toFixed(2));
        $this->assertStringContainsString('"ventaGravada":9.975,', $r->json);
        $this->assertSame(36, $doc['cuerpoDocumento'][0]['uniMedida']);
    }

    public function test_varias_formas_de_pago(): void
    {
        [, $doc] = $this->construir($this->venta(
            [new LineaVenta('Almuerzo', '2', '5.00')],
            pagos: [new Pago('01', '4.00'), new Pago('02', '6.00', referencia: 'AUT-1234')],
        ));

        $this->assertCount(2, $doc['resumen']['pagos']);
        $this->assertSame('AUT-1234', $doc['resumen']['pagos'][1]['referencia']);
    }

    public function test_rechaza_pagos_que_no_cuadran(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta(
            [new LineaVenta('Almuerzo', '2', '5.00')],
            pagos: [new Pago('01', '4.00'), new Pago('02', '5.00')],
        ));
    }

    public function test_venta_de_25000_o_mas_exige_identificar_al_cliente(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([new LineaVenta('Vehículo', '1', '25000.00')]));
    }

    public function test_venta_grande_con_cliente_identificado(): void
    {
        $cliente = new Receptor(tipoDocumento: '13', numDocumento: '00000000-0', nombre: 'MARIA LOPEZ');
        [$r] = $this->construir($this->venta([new LineaVenta('Vehículo', '1', '25000.00')], $cliente));

        $this->assertSame('25000.00', $r->totalPagar->toFixed(2));
    }

    public function test_rechaza_cantidad_cero(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([new LineaVenta('Nada', '0', '1.00')]));
    }

    public function test_rechaza_factura_sin_lineas(): void
    {
        $this->expectException(DteException::class);
        $this->construir($this->venta([]));
    }

    public function test_rechaza_ambiente_invalido(): void
    {
        $this->expectException(DteException::class);
        new FacturaBuilder($this->emisor(), '02');
    }

    public function test_iva_contenido(): void
    {
        $this->assertSame('1.30', FacturaBuilder::ivaContenido(Decimal::of('11.30'), 2)->toFixed(2));
        $this->assertSame('0.51769912', (string) FacturaBuilder::ivaContenido(Decimal::of('4.50'), 8));
    }
}
```

### Archivo: `tests/Unit/Dte/UtilidadesTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dte;

use App\Dte\Support\CodigoGeneracion;
use App\Dte\Support\Decimal;
use App\Dte\Support\DteJson;
use App\Dte\Support\NumeroALetras;
use App\Dte\Support\NumeroControl;
use PHPUnit\Framework\TestCase;

final class UtilidadesTest extends TestCase
{
    public function test_numero_a_letras(): void
    {
        $this->assertSame('SEIS CON 50/100 DÓLARES', NumeroALetras::convertir('6.50'));
        $this->assertSame('CERO CON 75/100 DÓLARES', NumeroALetras::convertir('0.75'));
        $this->assertSame('CIEN CON 00/100 DÓLARES', NumeroALetras::convertir('100'));
        $this->assertSame('CIENTO UNO CON 00/100 DÓLARES', NumeroALetras::convertir('101'));
        // Ejemplo oficial: "Cuatrocientos cincuenta y dos con 47/100 dólares"
        $this->assertSame('CUATROCIENTOS CINCUENTA Y DOS CON 47/100 DÓLARES', NumeroALetras::convertir('452.47'));
        $this->assertSame('UN MIL CIENTO TREINTA CON 00/100 DÓLARES', NumeroALetras::convertir('1130'));
        $this->assertSame('VEINTIÚN MIL QUINIENTOS CON 10/100 DÓLARES', NumeroALetras::convertir('21500.10'));
        $this->assertSame('UN MILLÓN DOSCIENTOS MIL CON 00/100 DÓLARES', NumeroALetras::convertir('1200000'));
        $this->assertSame('DOS MILLONES CON 01/100 DÓLARES', NumeroALetras::convertir('2000000.01'));
    }

    public function test_numero_control(): void
    {
        $n = NumeroControl::generar('01', 'M001', 'P001', 1);
        $this->assertSame('DTE-01-M001P001-000000000000001', $n);
        $this->assertSame(31, strlen($n));
        $this->assertTrue(NumeroControl::esValido($n, '01'));
        $this->assertFalse(NumeroControl::esValido($n, '03'));
        $this->assertSame('DTE-03-S012P007-000000000004521', NumeroControl::generar('03', 'S012', 'P007', 4521));
    }

    public function test_numero_control_rechaza_establecimiento_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NumeroControl::generar('01', 'X001', 'P001', 1);
    }

    public function test_codigo_generacion_es_uuid_v4_en_mayusculas(): void
    {
        $a = CodigoGeneracion::nuevo();
        $b = CodigoGeneracion::nuevo();
        $this->assertTrue(CodigoGeneracion::esValido($a));
        $this->assertSame(strtoupper($a), $a);
        $this->assertSame('4', $a[14]); // versión 4
        $this->assertNotSame($a, $b);
    }

    public function test_json_escribe_decimales_exactos_como_numeros(): void
    {
        $json = DteJson::encode([
            'total' => Decimal::of('6.50'),
            'iva' => Decimal::of('0.51769912'),
            'grande' => Decimal::of('99999999999.12345678'),
            'texto' => 'Pupusería',
        ]);
        $this->assertSame('{"total":6.5,"iva":0.51769912,"grande":99999999999.12345678,"texto":"Pupusería"}', $json);
    }

    public function test_json_rechaza_float(): void
    {
        $this->expectException(\LogicException::class);
        DteJson::encode(['total' => 6.5]);
    }
}
```

---

## FIN DEL DOCUMENTO

Cuando hayas creado todos los archivos y hecho el primer commit, dile al usuario en 10 líneas qué entendiste, qué esquemas oficiales descargaste o te faltan, y empieza el **Paso 0** de `PLAN.md` en modo autónomo.
