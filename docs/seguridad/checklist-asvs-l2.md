# Checklist de seguridad — OWASP ASVS 4.0.3, nivel 2

Estado: ✅ cumplido y probado · 🟡 parcial · ⏳ pendiente (depende del hosting, decisión 2 del PLAN).
Las pruebas automatizadas que lo respaldan se indican entre paréntesis.

## V1 Arquitectura
- ✅ Aislamiento multiempresa en dos capas: EmpresaScope + RLS forzado; rol de BD sin BYPASSRLS (`AislamientoTest`).
- ✅ Secretos de clientes cifrados con llave dedicada distinta de APP_KEY (`CredencialesTest`, `EndurecimientoTest`).
- ✅ Firmador solo en red interna, puertos publicados solo en 127.0.0.1 en desarrollo.
- ⏳ Diagrama de amenazas y segmentación de red del hosting.

## V2 Autenticación
- ✅ 2FA TOTP obligatorio para dueños y superadmin (`AutenticacionTest`).
- ✅ Límite de intentos de login (5/min) y de 2FA (`EndurecimientoTest`).
- ✅ Contraseñas con hash (bcrypt) y mínimo de 12 caracteres con mayúsculas, minúsculas y números en la invitación.
- ✅ Alta de dueños por invitación firmada de un solo uso, 72 h (`EndurecimientoTest`).
- 🟡 Recuperación de contraseña: no habilitada aún (soporte la gestiona); agregar flujo con enlace firmado.
- ✅ Credenciales del portal de Hacienda nunca se guardan.

## V3 Sesiones
- ✅ Sin cookie `XSRF-TOKEN` legible por JavaScript (el token va en la página) (`SeguridadTest`).
- ✅ Sesión regenerada al autenticarse; cookie HttpOnly, SameSite=Lax.
- ✅ En producción: `SESSION_SECURE_COOKIE` y `SESSION_ENCRYPT` (`plataforma:revisar-produccion`).
- ✅ La sesión del panel no sirve para la API (`ApiPublicaTest`).

## V4 Control de acceso
- ✅ Gates por rol en cada pantalla y acción (`PanelTest`).
- ✅ API con permisos por token (`ApiPublicaTest`).
- ✅ Soporte solo con permiso temporal de la empresa, solo consulta, sin credenciales (`PlataformaTest`).
- ✅ Horizon solo superadmin con 2FA (`EndurecimientoTest`).

## V5 Validación y codificación
- ✅ Todo DTE se valida contra el esquema oficial antes de firmar; montos exactos (sin float).
- ✅ Validación de entrada en panel y API; mensajes en español.
- ✅ Webhooks: bloqueo de destinos internos (SSRF) (`ApiPublicaTest`). ⏳ Proxy de egreso en producción.
- ✅ Blade escapa la salida por defecto.

## V6 Criptografía
- ✅ AES-256-GCM para secretos; rotación de llaves sin pérdida (`EndurecimientoTest`, runbook §7).
- ✅ Webhooks firmados con HMAC-SHA256 y marca de tiempo.
- ⏳ Llaves en el gestor de secretos del hosting (KMS).

## V7 Errores y bitácora
- ✅ Secretos nunca en logs ni excepciones (`#[\SensitiveParameter]`, `$hidden`, `dontFlash`).
- ✅ Bitácora inmutable (trigger sin UPDATE/DELETE/TRUNCATE) de acciones sensibles.
- ✅ `APP_DEBUG=false` verificado en producción.
- ⏳ Centralización de logs con retención (hosting).

## V8 Protección de datos
- ✅ JSON firmado y sello guardados sin modificar; DTE sellados inmutables (trigger).
- ✅ Respaldos con verificación de restauración en CI.
- ⏳ Cifrado en reposo del disco/BD y del bucket (servicio administrado).

## V9 Comunicaciones
- ✅ HSTS sobre HTTPS; TLS 1.2+ hacia Hacienda (cliente HTTP por defecto).
- ⏳ Certificados TLS y terminación en el balanceador.

## V10 Código malicioso / dependencias
- ✅ CI con Pint, Larastan nivel 6 y pruebas en cada push.
- ✅ `composer audit` y `npm audit` bloqueantes en el CI (se actualizó `web-auth/webauthn-lib` a 5.3.17 por 6 avisos).

## V11 Lógica de negocio
- ✅ Correlativo con `SELECT … FOR UPDATE`, sin repetidos bajo concurrencia (`tests/Concurrencia`, prueba de carga).
- ✅ Idempotencia de la API (`ApiPublicaTest`). La emisión nunca se bloquea por el plan (`PlataformaTest`).

## V12 Archivos
- ✅ Certificados en disco privado; solo XML bien formado sin DTD/ENTITY (XXE), extensión .crt/.xml, 512 KB (`SeguridadTest`).
- ✅ Recibidos limitados a JSON de 512 KB; scripts rechazados. Discos privados nunca servidos por HTTP (`serve` = false).

## V13 API
- ✅ Límite por token (120/min), 401/403 en español, OpenAPI documentada.
- ✅ CORS cerrado por defecto (`CORS_ORIGENES`), sin credenciales cruzadas.
- ✅ La bitácora atribuye las acciones de la API al token, nunca a una persona.

## V14 Configuración
- ✅ Cabeceras de seguridad y CSP estricta con nonce para scripts y estilos, sin `'unsafe-eval'` ni `'unsafe-inline'` (Livewire en modo `csp_safe`) (`EndurecimientoTest`, ZAP).
- ✅ `plataforma:revisar-produccion` verifica la configuración crítica.
- ⏳ WAF delante de la aplicación (servicio del hosting).

## Pruebas realizadas (25/11/2026)

| Prueba | Resultado |
| --- | --- |
| Suite automatizada `tests/Feature/Seguridad` (XSS, SQLi, IDOR, CSRF, manipulación de Livewire, subidas, XXE, enlaces firmados, sesión, open redirect, asignación masiva, recorrido de directorios, roles, RLS, secretos en log) | 18 pruebas, todas pasan; también corre en el CI |
| OWASP ZAP *baseline* (pasivo, sin sesión) | 0 fallas; avisos menores resueltos (versión de PHP, CSP, cookie sin HttpOnly) |
| OWASP ZAP *full scan* (activo, con sesión iniciada, todas las pantallas internas con parámetros) | 0 fallas en 111 reglas (inyección SQL, XSS reflejado y almacenado, recorrido de directorios, inyección de comandos, CRLF, entre otras) |
| Hallazgos corregidos | Precio de la Caja manipulable desde el navegador; certificado sin validar (XXE); CSP con `unsafe-eval`; cookie `XSRF-TOKEN` sin HttpOnly; CORS abierto en la API; bitácora que atribuía acciones de la API a un usuario por coincidencia de id; disco privado servible por HTTP |
| Pendiente (hosting) | WAF, TLS, KMS, pruebas de penetración externas sobre staging |
