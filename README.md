# Escobar Net DTE — Plataforma SaaS de Facturación Electrónica

Plataforma multiempresa para emitir, firmar, transmitir, entregar, consultar e invalidar
Documentos Tributarios Electrónicos (DTE) ante el Ministerio de Hacienda de El Salvador.

- Reglas permanentes: [`CLAUDE.md`](CLAUDE.md)
- Qué se construye: [`ESPECIFICACION.md`](ESPECIFICACION.md)
- En qué orden y estado actual: [`PLAN.md`](PLAN.md)
- Documentos oficiales: [`docs/oficial/`](docs/oficial/) · Esquemas JSON: [`resources/schemas/`](resources/schemas/)

## Requisitos

- PHP 8.3 o superior (extensiones `pdo_pgsql`, `redis`, `intl`, `mbstring`; recomendado `bcmath`).
- Composer 2.
- Docker con Docker Compose.

## Puesta en marcha

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d          # postgres, redis, s3, firmador, mailpit
php artisan migrate
php artisan test
```

| Servicio | Para qué | Dirección local |
|---|---|---|
| `postgres` | Base de datos (`dte`) y base de pruebas (`dte_test`) | `127.0.0.1:5432` |
| `redis` | Colas y caché (Horizon) | `127.0.0.1:6379` |
| `s3` | Almacenamiento compatible con S3 (SeaweedFS), bucket `dte-archivo` con versionado | `http://127.0.0.1:8333` |
| `firmador` | Firmador oficial de Hacienda (`svfe-api-firmador`) | `http://127.0.0.1:8113/firmardocumento/` |
| `mailpit` | Ver los correos de desarrollo | `http://127.0.0.1:8025` |

Todos los puertos se publican solo en `127.0.0.1`. En producción el firmador **no** se publica.

> **Nota sobre S3:** el plan decía MinIO para desarrollo, pero sus imágenes ya no se publican en Docker Hub.
> Se usa SeaweedFS, que habla el mismo protocolo S3; el código de Laravel no cambia.

### Firmador

Se compila desde el código fuente oficial en [`docker/firmador/`](docker/firmador/). Los certificados
`{NIT}.crt` van en `storage/app/private/certificados/` (montado en solo lectura). Prueba rápida:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"nit":"00000000000000","activo":true,"passwordPri":"x","dteJson":{}}' \
  http://127.0.0.1:8113/firmardocumento/
# {"status":"ERROR","body":{"codigo":"803","mensaje":"No existe llave publica para este nit"}}
```

## Calidad

```bash
php artisan test                    # Pest (con PostgreSQL, nunca SQLite)
./vendor/bin/pint                   # estilo
./vendor/bin/phpstan analyse        # Larastan nivel 6
php artisan horizon                 # supervisor de colas
```

Las pruebas reales contra Hacienda (grupo `hacienda-pruebas`) están excluidas por defecto y necesitan
credenciales de pruebas en `.env`.

El CI (GitHub Actions, `.github/workflows/ci.yml`) corre Pint, Larastan y las pruebas en cada push,
y construye la imagen del firmador.
