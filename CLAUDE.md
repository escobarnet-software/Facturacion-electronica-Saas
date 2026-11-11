# CLAUDE.md — Plataforma DTE de Escobar Net

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
