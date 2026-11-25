#!/usr/bin/env bash
# Respaldo de PostgreSQL (formato custom, comprimido) con suma de verificación.
# Uso: PGHOST=… PGUSER=… PGPASSWORD=… PGDATABASE=dte scripts/respaldo-bd.sh [carpeta]
# Si RESPALDO_S3_URI está definido (s3://bucket/ruta), sube el archivo con la CLI de AWS (cifrado en el bucket).
# El bucket debe tener versionado y object lock; la retención se define con su ciclo de vida.
set -euo pipefail

carpeta="${1:-./respaldos}"
mkdir -p "$carpeta"
marca="$(date -u +%Y%m%dT%H%M%SZ)"
archivo="$carpeta/${PGDATABASE:-dte}-$marca.dump"

pg_dump --format=custom --compress=9 --no-owner --no-privileges --file="$archivo"
(cd "$carpeta" && sha256sum "$(basename "$archivo")" > "$(basename "$archivo").sha256")
echo "Respaldo creado: $archivo ($(du -h "$archivo" | cut -f1))"

if [[ -n "${RESPALDO_S3_URI:-}" ]]; then
  aws s3 cp "$archivo" "$RESPALDO_S3_URI/" --sse AES256 ${AWS_ENDPOINT:+--endpoint-url "$AWS_ENDPOINT"}
  aws s3 cp "$archivo.sha256" "$RESPALDO_S3_URI/" --sse AES256 ${AWS_ENDPOINT:+--endpoint-url "$AWS_ENDPOINT"}
  echo "Subido a $RESPALDO_S3_URI"
fi
