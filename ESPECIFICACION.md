# ESPECIFICACION.md — Plataforma SaaS de Facturación Electrónica (DTE) · Escobar Net

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
