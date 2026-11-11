# Firmador oficial (svfe-api-firmador)

Código fuente oficial del Ministerio de Hacienda, descargado de
https://factura.gob.sv/informacion-tecnica-y-funcional/ («5. svfe-api-firmador», versión 2.0.0, el 2026-11-11).

- **No se modifica el código Java.** Solo se agregó `Dockerfile` (compilación en dos etapas); el original está en `Dockerfile.oficial`.
- Escucha en el puerto **8113**: `POST /firmardocumento/`.
- Lee los certificados de `/uploads/{NIT}.crt`. En `docker-compose.yml` se monta `storage/app/private/certificados` en solo lectura.
- Solo vive en la red interna. En desarrollo se publica únicamente en `127.0.0.1:8113`; en producción **no se publica**.
