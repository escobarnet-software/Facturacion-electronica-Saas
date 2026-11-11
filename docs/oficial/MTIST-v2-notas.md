# Notas del MTIST v2 (mayo 2026)

`MTIST-v2.pdf` es un documento escaneado (sin texto buscable). Estas notas transcriben lo que usa el código.
Si algo choca con el PDF, manda el PDF.

## Holguras (3.1)
Se reciben documentos con **un día** posterior a la fecha de transmisión, excepto el **último día del período
tributario**: ahí solo **30 minutos** de diferencia hacia adelante respecto a la hora del servicio de recepción.

## Política de reintentos (3.3)
1. Si el servicio no responde en **8 segundos**: consultar el estado; si no fue recibido, reenviar. Máximo **2 veces**.
2. Si falla el servicio del emisor y no procesa la respuesta: igual (consultar, y reenviar hasta 2 veces).
3. Si aun así no se recibe: operar en **contingencia**.

## Lotes (3.2.1, 3.4)
- Máximo **100 DTE por lote**, hasta **400 lotes**. Un lote tarda de 1 a 3 minutos.
- Horario de lotes: pruebas 08:00–17:00; producción 22:00–05:00 (solo para facturación cíclica).
  Los lotes de **contingencia** se reciben 24 h, los 365 días.

## Servicios (4)
| Servicio | Método y ruta | Cuerpo |
|---|---|---|
| Autenticación | `POST /seguridad/auth` (`application/x-www-form-urlencoded`) | `user`, `pwd` |
| Recepción uno a uno | `POST /fesv/recepciondte` (JSON) | `ambiente`, `idEnvio` (**entero**, correlativo a discreción), `version` (= `identificacion.version`), `tipoDte`, `documento` (JWS), `codigoGeneracion` |
| Recepción por lote | `POST /fesv/recepcionlote` | `ambiente`, `idEnvio` (**UUID v4 en mayúsculas**), `version` (del esquema de lotes), `nitEmisor`, `documentos` [JWS] |
| Consulta de DTE | `POST /fesv/recepcion/consultadte` | `nitEmisor`, `tdte`, `codigoGeneracion` |
| Consulta de lote | `GET /fesv/recepcion/consultadtelote/{codigoLote}` | — |
| Contingencia | `POST /fesv/contingencia` | `nit`, `documento` (JWS del evento) |
| Invalidación | `POST /fesv/anulardte` | `ambiente`, `idEnvio` (entero), `version`, `documento` (JWS del evento) |

Encabezados: `Authorization: <token>`, `User-Agent` (el manual no exige un valor concreto), `Content-Type`.
Las rutas van exactas, **sin barra final** (6.2).

### Autenticación
- Éxito (HTTP 200): `{"status":"OK","body":{"user":"…","token":"Bearer eyJ…","rol":{…},"roles":[…],"tokenType":"Bearer"}}`.
  **El token ya trae el prefijo `Bearer`.**
- Error: `{"status":"ERROR","error":"Unauthorized","message":"Usuario no valido"}`.
- Se ejecuta una vez al día o según el modelo de facturación.
- Códigos 100–111 (100 usuario incorrecto, 103 contraseña expirada, 106 credenciales inválidas, 107 token inválido…).

### Recepción
- Éxito (HTTP 200): `estado: "PROCESADO"`, `selloRecibido`, `fhProcesamiento` (`dd/MM/yyyy HH:mm:ss`),
  `clasificaMsg: "10"`, `codigoMsg: "001"` (`RECIBIDO`) o `"002"` (`RECIBIDO CON OBSERVACIONES`), `observaciones` [texto].
  Ojo: el ejemplo sin observaciones trae `"observaciones": ["", ""]` (cadenas vacías).
- Rechazo (HTTP 400): `estado: "RECHAZADO"`, `selloRecibido: null`, `codigoMsg`, `descripcionMsg`, `observaciones`.
- Lote recibido: `estado: "RECIBIDO"`, `codigoLote`. La consulta de lote devuelve `procesados[]` y `rechazados[]` (con `index`).
- Contingencia: `{"estado":"RECIBIDO"|"RECHAZADO","fechaHora","mensaje","selloRecibido","observaciones"}`.

### Códigos de respuesta de recepción (pág. 22–23)
1 RECIBIDO · 2 RECIBIDO CON OBSERVACIONES · 3 VALOR NO VALIDO · 4 YA EXISTE UN REGISTRO CON ESE VALOR ·
5 DEBE PROPORCIONAR UN VALOR · 6 FORMATO NO VALIDO · 7 VALOR NO ES PERMITIDO · 8 NO CORRESPONDE A CONTRIBUYENTE ·
9 NIT CONTRIBUYENTE NO EXISTE · 10 CONTRIBUYENTE NO SE ENCUENTRA ACTIVO · 11 NO ES CONTRIBUYENTE IVA ·
12 NO EXISTE EVENTO DE CONTINGENCIA · 13 NO ESTA ENTRE PERIODO REPORTADO EN EVENTO DE CONTINGENCIA ·
14 NO EXISTE UN REGISTRO CON ESTE DATO · 15 DOCUMENTO SE ENCUENTRA INVALIDADO · 16 MONTO ES MAYOR AL DOCUMENTO RELACIONADO ·
17 FECHA NO ES CORRECTA · 18 FECHA FUERA DE PLAZO PERMITIDO · 19 ERROR EN CORRELATIVO · 20 CALCULO INCORRECTO ·
21 NO SE PUEDE VALIDAR CAMPO · 22 SERVICIO DE VALIDACION DE FIRMA NO DISPONIBLE · 23 FALTAN DATOS PARA VALIDAR INFORMACION ·
24 VALOR EN CAMPO NO APLICA · 25 VALORES REPETIDOS · 26 NO SE PERMITEN VALORES DIFERENTES · 27 DATO NO COINCIDE CON DTE ·
28 DTE ESTA RELACIONADO CON OTRO DTE · 29 NIT DE CONTRIBUYENTE NO ES VALIDO · 30 NIT FUSION NO ES VALIDO ·
31 NIT DE CONTRIBUYENTE NO AUTORIZADO · 32 DIFIERE DE LA FECHA DE ENVIO · 33 DOCUMENTO SIN SELLO ·
34 EXCEDE HOLGURA DE 30 MINUTOS · 94 PARAMETROS NO SON VALIDOS · 95 CONTRIBUYENTE NO AUTORIZADO PARA EMITIR TIPO DE DTE ·
96 DOCUMENTO NO CUMPLE CON NORMATIVA DE CUMPLIMIENTO · 97 NO SE HA PROPORCIONADO DATOS PARA VALIDAR ·
98 DATOS NO CUMPLEN ESTANDAR JSON · 99 ERROR NO CATALOGADO · 100–111 (autenticación) ·
112 CODIGO DE GENERACION REEMPLAZO, NO EXISTE · 113 Número de documento es solicitado para invalidar ·
114 Tipo de documento es solicitado para invalidar · 115 NO SE PERMITEN TRIBUTOS DE USO INFORMATIVO · 116 Fecha menor a 01-01-2000.

## Código QR (5)
`https://admin.factura.gob.sv/consultaPublica?ambiente={ambiente}&codGen={codigoGeneracion}&fechaEmi={AAAA-MM-DD}`

## Consideraciones (6)
- JSON limpio, UTF-8, sin caracteres sobrantes (como `;` al final).
- El JSON Schema es referencial: no exime de los cálculos fiscales.
- Nunca enviar DTE de prueba a producción.
- TLS 1.2 o superior; tokens y credenciales gestionados de forma segura y cifrada.
- Infraestructura solo en jurisdicciones con tratados de extradición y asistencia legal en ciberdelitos con El Salvador.
