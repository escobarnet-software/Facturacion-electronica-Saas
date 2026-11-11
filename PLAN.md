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
- [ ] Paso 0 · [ ] 1 · [ ] 2 · [ ] 3 · [ ] 4 · [ ] 5 · [ ] 6 · [ ] 7 · [ ] 8 · [ ] 9 · [ ] 10 · [ ] 11 · [ ] 12 · [ ] 13 · [ ] 14 · [ ] 15
