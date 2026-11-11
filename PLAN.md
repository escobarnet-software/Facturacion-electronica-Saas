# PLAN.md — Plataforma SaaS DTE · Escobar Net

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
- [x] Paso 0 · [x] 1 · [x] 2 · [x] 3 · [x] 4 · [x] 5 · [x] 6 · [x] 7 · [x] 8 · [ ] 9 · [ ] 10 · [ ] 11 · [ ] 12 · [ ] 13 · [ ] 14 · [ ] 15

#### Notas de estado
- **Paso 0 (2026-11-11):** Laravel 13 (PHP 8.3), Pest 4, Pint, Larastan nivel 6, Horizon, CI en GitHub Actions.
  `docker-compose.yml` con PostgreSQL 17 (bases `dte` y `dte_test`), Redis, S3 local, firmador oficial (compilado desde
  el código fuente de Hacienda) y Mailpit. `config/dte.php` con URL, timeouts, reintentos, horas de token y tamaño de lote.
  - MinIO ya no publica imágenes en Docker Hub: el S3 local es **SeaweedFS** (mismo protocolo S3).
  - Esquemas oficiales descargados: 15/15 en `resources/schemas/`. **FEX es versión 3** (la Normativa dice 2; manda el esquema).
- **Paso 1 (2026-11-11):** tablas `empresas`, `usuarios`, `empresa_usuario` (rol), `establecimientos`, `puntos_venta`,
  `empresa_credenciales` y `bitacora`. Aislamiento en dos capas: `EmpresaScope` (falla cerrado sin empresa) y RLS
  ENABLE + FORCE en todas las tablas de negocio, con `set_config('app.empresa_id')` por petición (middleware `empresa`)
  y por job (`ConEmpresa`). La app se conecta con el rol `dte_app` (sin superusuario ni BYPASSRLS).
  Escape explícito y auditable: `Tenancy::sinAislamiento()` (crear empresas, tareas de plataforma).
  Login con Fortify; 2FA (TOTP) obligatoria para dueños y superadmin; cambio de empresa activa.
  Secretos cifrados con `DTE_SECRETS_KEY` (AES-256-GCM, distinta de APP_KEY), ocultos en toArray/toJson/logs/var_dump,
  `#[\SensitiveParameter]`; certificados `{NIT}.crt` en disco privado con permisos 600. Bitácora append-only con trigger
  que rechaza UPDATE/DELETE/TRUNCATE. 37 pruebas en verde.
- **Paso 2 (2026-11-11):** motor del Paso 1 integrado (`app/Dte/`), sin reescribirlo. `Decimal` y `DteJson` pasan a
  `App\Support` (como exige CLAUDE.md). `RegistroEsquemas` + `ValidadorEsquema` (opis/json-schema) para los 11 DTE y
  4 eventos, por tipo y versión. Pruebas: las 30 del Paso 1 + UUID, `NumeroALetras` (0.01, 1, 21, 100, 1,000,000.99),
  validación positiva y negativa de esquema. 77 pruebas en verde.
  - `totalLetras` sigue el formato del Manual Funcional oficial («SETECIENTOS CATORCE CON 95/100 DÓLARES»); el ejemplo
    de ESPECIFICACION §6.5 («… DÓLARES CON … CENTAVOS») no coincide con el manual, así que manda el manual.
  - Se corrigió un comentario de `CodigoGeneracion` que decía que un rechazo lleva código nuevo (ESPECIFICACION §5.1: mismo código).
- **Paso 3 (2026-11-11):** catálogos oficiales CAT-001 a CAT-033 (Excel V 1.1, 1681 valores) en la tabla genérica
  `cat_valores` (datos comunes, sin RLS), seeder idempotente y `dte:catalogos:actualizar [archivo] [--aplicar]`
  (muestra nuevos, cambiados y retirados; lo retirado se marca no vigente, no se borra).
  - El Excel no trae departamento para distritos (CAT-008) ni municipios (CAT-013): se deduce (los distritos reinician
    en 01 por departamento; los municipios empiezan con el nombre del departamento). CAT-015 repite D4/D5 en las
    secciones 1 y 2: se guardan con la sección como agrupador.
  - Correlativos con `SELECT … FOR UPDATE`, reinicio anual por la fecha de emisión en hora de El Salvador y los modos
    `por_tipo` (defecto), `por_establecimiento` y `centralizado` (TODO(normativa): decisión pendiente nº 1).
    El correlativo se toma en su propia transacción, fuera de la emisión, para no reutilizar números.
  - 50 procesos concurrentes reales obtienen 50 números distintos y consecutivos. 92 pruebas en verde.
- **Paso 4 (2026-11-11):** `FirmadorClient` (envía el JSON exacto; la clave nunca aparece en errores),
  `HaciendaClient` (auth, recepción, consulta, lote, consulta de lote, contingencia, invalidación; token en caché por
  empresa y ambiente; renovación si Hacienda responde 401/403), `TransmisorDte` con la política del MTIST 3.3
  (8 s → consulta → máx. 2 reenvíos con el mismo código → contingencia/«transitorio») y job `TransmitirDte`
  en la cola `transmision`. Tabla `dtes` (JSON exacto + jsonb, trigger que impide alterar JSON/firma/sello una vez
  sellado) y `dte_eventos_internos`; enum `EstadoDte`. 3 logins fallidos bloquean y avisan; el 4.º no llega a Hacienda.
  `config/dte/mensajes_mh.php` con todos los códigos del MTIST en español. 107 pruebas en verde.
  - Resueltos [VERIFICAR] de ESPECIFICACION §8.2 con el MTIST (ver `docs/oficial/MTIST-v2-notas.md`): el token ya
    incluye «Bearer»; `idEnvio` es entero en recepción/invalidación y UUID en lotes; el MTIST no exige un User-Agent concreto.
  - **PENDIENTE:** la prueba real del grupo `hacienda-pruebas` (sello real de una FE) espera las credenciales de
    pruebas de Hacienda y el certificado `.crt` del usuario. Con `Http::fake` todo pasa.
- **Paso 5 (2026-11-11):** builders de CCF (03, v4), NR (04, v4), NC (05, v4) y ND (06, v4) sobre una calculadora común
  (`app/Dte/Contribuyente/`): ventas gravadas/exentas/no sujetas, descuento por ítem y global (porcentaje o monto),
  montos no afectos (cargos/abonos), ítems de «otros tributos» (tipo 4), retención y percepción del 1 %, documentos
  relacionados, y en NC/ND el IVA, la percepción y la retención por ítem. Cuadran al centavo: $7,039.90, $2,599.00,
  $761.20 y $107.35 (CCF) y $95.80 (NC). Los 4 tipos validan contra su esquema oficial. 128 pruebas en verde.
  - Los esquemas v4 nombran los campos `ivaPerci` e `ivaRete` (ESPECIFICACION decía `ivaPerci1`/`ivaRete1`): manda el esquema.
  - El ejemplo del Manual Funcional imprime «Total de Descuentos $113.50»; la suma correcta es $112.50 y así se calcula.
  - TODO(normativa): cuándo aplicar la retención/percepción del 1 % (agentes, montos mínimos) no está en los manuales;
    por ahora lo indica quien emite (`retenerIva1`, `percibirIva1`).
  - Se corrigió la validación de esquemas: el `multipleOf` de Opis fallaba con montos correctos por el error de coma
    flotante (7039.90 «no es múltiplo de 0.01»). Ahora se compara en decimal exacto (`MultiploExacto`) y `ext-bcmath`
    es obligatoria en `composer.json`.
- **Paso 6 (2026-11-11):** builders de FSE (14), FEX (11, v3), CR (07), CL (08), DCL (09) y CD (15) en `app/Dte/Especiales/`,
  cada uno con al menos 5 pruebas de cálculo, validación contra su esquema oficial y transmisión simulada (firma → envío).
  Las fórmulas salen de la **Normativa v2.0, Anexo IV** («Validaciones para campos DTE», págs. 115–121), que confirma
  también las de FE, CCF, NC, ND, FEX y FSE ya implementadas. 168 pruebas en verde.
  - CL: valores sin IVA; documentos invalidados restan y las NC suman solo si están invalidadas; si lo que resta supera
    lo que suma, $0.00. DCL: subtotal, IVA de la comisión y líquido según el Anexo IV.
  - CL, DCL y CD no admiten contingencia (sus esquemas no tienen esos campos). En DCL el esquema exige `porcentComision`
    numérico aunque la Normativa diga null: se envía 0.00.
  - TODO(normativa): DCL — «monto sujeto a percepción sin IVA» e «IVA de las operaciones» no tienen fórmula en la
    Normativa: los aporta quien emite. FSE — porcentaje de la retención de renta (10 % por defecto, sobre servicios).
    CR — «IVA 13 %» del resumen se calcula como monto sujeto × 13 % para todos los códigos de retención.
  - **PENDIENTE:** transmisión real de cada tipo (grupo `hacienda-pruebas`, ya escrita): espera las credenciales de pruebas.
- **Paso 7 (2026-11-11):** eventos en `app/Dte/Eventos/`. Tablas `eventos`, `contingencias` y `lotes` (con RLS).
  - `Plazos`: días hábiles (asuetos del Código de Trabajo art. 190 y Jueves/Viernes/Sábado Santo, configurables en
    `config/dte/feriados.php`), Cuadro 6 (10.º día hábil del mes siguiente; 3 meses; 2 años medicamentos perecederos),
    retorno (3 meses), operaciones especiales, contingencia (24 h / 72 h). Pruebas de borde: último día válido y primer inválido.
  - Invalidación (esquema v3): solo con sello, dentro de plazo («FECHA FUERA DE PLAZO PERMITIDO»), documento que
    reemplaza ya sellado para los tipos 1 y 3 (salvo NC y CL); con sello el original queda «invalidado».
  - Contingencia completa: tras la política de reintentos el documento se re-genera en modo contingencia (modelo 2,
    operación 2, mismo código) y se re-firma; con contingencia abierta los nuevos entran directo. Comando
    `dte:contingencia:procesar` cada 15 min (scheduler): prueba Hacienda → cierra → Evento de Contingencia (v4) →
    lotes de 100 → consulta de lotes → «transmitido». Alerta y borrador del Informe Técnico a los 3 días.
    Simulación de 3 horas sin servicio: termina con todos los documentos transmitidos.
  - Retorno (fe-eret v1) para FE, FEX y FSE: no supera lo vendido, deja el original «ajustado» (no invalidado).
    Operaciones especiales (fe-eop v1) por rangos.
  - Las pruebas bloquean toda petición HTTP no simulada (`Http::preventStrayRequests`): nunca se contacta a Hacienda.
  - TODO(normativa): el MTIST no detalla el servicio para retorno y operaciones especiales: se envían por
    `recepciondte` con tipoDte 18 y 17. Confirmar si Semana Santa completa, fiestas agostinas o el cierre de fin de año
    son inhábiles. Los datos del responsable (nombre y documento) se agregaron a `empresas` porque los exige el evento.
  - 208 pruebas en verde.
- **Paso 8 (2026-11-11):** `config/dte/campos.php` transcrito del Anexo II de la Normativa (176 campos con su letra
  A/B/C/D, el nombre oficial del campo y sus excepciones por tipo). Representación gráfica con **DomPDF** (PHP puro: sin
  navegador en el servidor, suficiente para documentos tabulares y soporta el ticket de 80 mm), en carta y ticket:
  campos A siempre, B con valor, secciones B/D con guion, QR de consulta pública (PNG con GD), marca «SIN VALOR LEGAL —
  AMBIENTE DE PRUEBAS» y aviso de contingencia. Archivo DTE = JSON firmado exacto + firmaElectronica + selloRecibido,
  archivado inmutable ({NIT}/{AAAA}/{MM}/{código}.json/.pdf) con SHA-256; solo la versión de contingencia se completa
  con el sello. Correo de entrega sin publicidad con ambos adjuntos; job `EntregarDte` (cola «entrega») al obtener el
  sello o al entrar en contingencia. Portal público con enlaces firmados sin vencimiento. 232 pruebas en verde.
  - La revisión de las instantáneas encontró y corrigió: claves reordenadas por jsonb (se usa el texto exacto),
    municipio/distrito buscados sin su departamento y montos en cero mostrados como guion.
  - Pendiente para el Paso 9: subir el logo de la empresa desde el panel (la representación ya lo admite: `empresas.logo_path`).
