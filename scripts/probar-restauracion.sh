#!/usr/bin/env bash
# Prueba de restauración: restaura un respaldo en una base TEMPORAL y verifica que sirve.
#   1. La suma de verificación coincide.
#   2. Todas las tablas existen y los conteos de filas coinciden con la base de origen (si se indica).
#   3. RLS sigue activado y forzado en las tablas de negocio, con sus políticas.
#   4. Los triggers de inmutabilidad (bitácora y DTE sellados) existen.
# Uso: PGHOST=… PGUSER=<rol con CREATEDB> PGPASSWORD=… scripts/probar-restauracion.sh archivo.dump [base_origen]
set -euo pipefail

archivo="$1"
origen="${2:-}"
temporal="restauracion_prueba_$(date +%s)"

(cd "$(dirname "$archivo")" && sha256sum -c -s "$(basename "$archivo").sha256") && echo "[ok] Suma de verificación" || { echo "[FALLA] La suma de verificación no coincide"; exit 1; }

limpiar() { dropdb --if-exists "$temporal" >/dev/null 2>&1 || true; }
trap limpiar EXIT

createdb "$temporal"
pg_restore --no-owner --no-privileges --exit-on-error --dbname="$temporal" "$archivo"
echo "[ok] Restaurado en la base temporal $temporal"

q() { psql -X -A -t -v ON_ERROR_STOP=1 --dbname="$1" -c "$2"; }

fallas=0
tablas=$(q "$temporal" "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")
echo "[ok] $tablas tablas"

sin_rls=$(q "$temporal" "SELECT string_agg(c.relname, ', ') FROM pg_class c JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'empresa_id'
  JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
  WHERE c.relkind = 'r' AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity)")
if [[ -n "$sin_rls" ]]; then echo "[FALLA] Tablas con empresa_id sin RLS forzado: $sin_rls"; fallas=$((fallas+1)); else echo "[ok] RLS activado y forzado en todas las tablas con empresa_id"; fi

politicas=$(q "$temporal" "SELECT count(*) FROM pg_policies WHERE schemaname = 'public'")
[[ "$politicas" -gt 0 ]] && echo "[ok] $politicas políticas RLS" || { echo "[FALLA] Sin políticas RLS"; fallas=$((fallas+1)); }

for trig in bitacora_sin_update_delete bitacora_sin_truncate dtes_sellado_inmutable; do
  existe=$(q "$temporal" "SELECT count(*) FROM pg_trigger WHERE tgname LIKE '${trig}%'")
  [[ "$existe" -gt 0 ]] && echo "[ok] Trigger $trig" || { echo "[FALLA] Falta el trigger $trig"; fallas=$((fallas+1)); }
done

if [[ -n "$origen" ]]; then
  for t in $(q "$origen" "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1"); do
    # Conteo con el escape auditado de RLS (la prueba la hace un rol de operación).
    a=$(q "$origen" "SET app.sin_aislamiento = 'on'; SELECT count(*) FROM \"$t\"" | tail -1)
    b=$(q "$temporal" "SET app.sin_aislamiento = 'on'; SELECT count(*) FROM \"$t\"" | tail -1)
    if [[ "$a" != "$b" ]]; then echo "[FALLA] $t: origen $a, restaurada $b"; fallas=$((fallas+1)); fi
  done
  [[ $fallas -eq 0 ]] && echo "[ok] Los conteos de filas coinciden con $origen"
fi

if [[ $fallas -eq 0 ]]; then echo "RESTAURACIÓN VERIFICADA"; else echo "RESTAURACIÓN CON $fallas FALLA(S)"; exit 1; fi
