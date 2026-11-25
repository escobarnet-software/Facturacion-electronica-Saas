# Runbook de incidentes — Escobar Net DTE

Guía para quien esté de turno. Regla general: **la emisión de los clientes no se detiene**. Si Hacienda o el
firmador fallan, los documentos entran en contingencia y se entregan igual (estado «transitorio»).

Herramientas: superadmin → **Estado** (`/admin/estado`), **Horizon** (`/horizon`), `GET /salud`, bitácora,
alertas por correo de `plataforma:vigilar` (cada minuto, sin repetir la misma alerta en 30 min).

---

## 1. Hacienda no responde o falla («Hacienda está fallando»)
**Síntomas:** alerta, errores en `/admin/estado`, documentos en «transitorio», contingencias activas.
1. Confirmar en `/admin/estado` qué servicio falla (autenticación, recepción, consulta) y en qué ambiente.
2. No hacer nada sobre los documentos: el sistema reintenta (consulta + 2 reenvíos, timeout 8 s) y luego abre
   la contingencia sola. El scheduler (`dte:contingencia:procesar`, cada 15 min) cierra la contingencia cuando
   Hacienda vuelve, transmite el evento (≤ 24 h) y los lotes (≤ 72 h desde el sello del evento).
3. Verificar que el scheduler corre (`ROL=scheduler`) y que Horizon procesa la cola `transmision`.
4. Si la caída pasa de **3 días**, la empresa debe presentar Informe Técnico: el borrador está en el panel
   de contingencia de cada empresa.
5. Avisar a los clientes solo si la contingencia dura más de 4 h (correo, sin publicidad).

## 2. El firmador no acepta conexiones
1. `docker compose ps firmador` / estado del servicio en el hosting; reiniciarlo.
2. Revisar que el volumen de certificados (`/uploads`) esté montado y legible: cada empresa necesita `{NIT}.crt`.
3. Mientras está caído, los DTE quedan en «borrador»/«validado» en la cola; al volver, Horizon los reintenta.
   **No** se abre contingencia por falla del firmador (es infraestructura propia).
4. Si el error es de una sola empresa («certificado no encontrado» o contraseña de la llave incorrecta), avisar a
   esa empresa: los secretos son suyos y solo ella los cambia en Configuración.

## 3. Cola de transmisión atrasada
1. Horizon → ver la cola `transmision` y los jobs fallidos.
2. Escalar workers (aumentar `maxProcesses` del supervisor de Horizon o réplicas `ROL=horizon`).
3. Jobs fallidos: revisar la excepción; reintentar con `php artisan horizon:retry` o desde Horizon.
   Un DTE rechazado se corrige y se reenvía **con el mismo `codigoGeneracion`**.

## 4. Usuario API de un cliente bloqueado (3 logins fallidos)
La plataforma deja de intentar al tercer fallo para no bloquear al cliente en Hacienda. El cliente debe revisar
su contraseña en el portal de Hacienda y actualizarla en **Configuración**. Nunca pedirle la contraseña.

## 5. Base de datos
- **Caída:** `/salud` devuelve 503. Verificar el servicio administrado; las aplicaciones se reconectan solas.
- **Restaurar un respaldo:** ver §8. Restaurar siempre primero en una base temporal y verificar.
- **Nunca** conectar la aplicación con un rol con superusuario o BYPASSRLS (`plataforma:revisar-produccion`).

## 6. Sospecha de filtración de credenciales o llaves
1. Rotar `APP_KEY` y `DTE_SECRETS_KEY` (§7). Revocar todos los tokens de API afectados (pantalla API de cada
   empresa o `DELETE FROM personal_access_tokens` para todas, avisando a los clientes).
2. Avisar a las empresas afectadas para que cambien su contraseña de la API de Hacienda y, si corresponde, la
   de su llave privada. La plataforma **no** puede cambiarlas por ellas.
3. Revisar la bitácora (accesos de soporte, cambios de credenciales, logins fallidos).

## 7. Rotación de llaves
**DTE_SECRETS_KEY** (credenciales de los clientes):
1. Generar llave nueva: `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"`.
2. Desplegar con `DTE_SECRETS_KEY=<nueva>` y `DTE_SECRETS_PREVIOUS_KEYS=<anterior>`.
3. `php artisan dte:secretos:rotar` y luego `php artisan dte:secretos:rotar --verificar` (debe decir que todo usa la llave actual).
4. Quitar `DTE_SECRETS_PREVIOUS_KEYS` y desplegar. Destruir la llave anterior.

**APP_KEY** (sesiones, enlaces firmados): desplegar con la nueva en `APP_KEY` y la anterior en `APP_PREVIOUS_KEYS`;
retirarla tras la vida de las sesiones (2 h). Los enlaces del portal se firman con APP_KEY: mantener la anterior
mientras se necesite que los enlaces viejos sigan funcionando.

## 8. Respaldos y restauración
- Respaldo: `scripts/respaldo-bd.sh` (pg_dump formato custom + SHA-256; con `RESPALDO_S3_URI` lo sube cifrado).
  Programarlo cada noche y además usar el respaldo continuo (PITR) del servicio administrado.
- Prueba de restauración: `scripts/probar-restauracion.sh <archivo> <base_origen>` restaura en una base temporal y
  verifica suma, tablas, RLS forzado, políticas, triggers de inmutabilidad y conteos. Corre en el CI en cada push
  y debe correrse **cada mes en staging** con el respaldo real de producción.
- El archivo inmutable de los DTE (JSON y PDF) vive en S3 con versionado y object lock: no se restaura desde la BD.

## 9. Despliegue y reversión
1. `ROL=migrar` (migraciones) → réplicas `web`, `horizon`, `scheduler` con la nueva imagen.
2. Revertir = volver a la imagen anterior. Las migraciones deben ser compatibles hacia atrás una versión.
3. Tras desplegar: `php artisan plataforma:revisar-produccion` y revisar `/salud`.
