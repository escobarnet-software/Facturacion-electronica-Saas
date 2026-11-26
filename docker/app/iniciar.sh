#!/bin/sh
# ROL=web (por defecto) | horizon | scheduler | migrar | demo (migra, siembra catálogos y crea la empresa demo)
set -e
cd /var/www/html
# Carpeta privada de certificados (puede ser un volumen nuevo): solo la aplicación la lee y escribe.
mkdir -p storage/app/private/certificados && chown www-data:www-data storage/app/private/certificados && chmod 700 storage/app/private/certificados
php artisan config:cache && php artisan route:cache && php artisan view:cache
case "${ROL:-web}" in
  web)       exec supervisord -c /etc/supervisord.conf ;;
  horizon)   exec php artisan horizon ;;
  scheduler) exec php artisan schedule:work ;;
  migrar)    exec php artisan migrate --force ;;
  demo)      php artisan migrate --force --seed && exec php artisan dte:demo ;;
  *) echo "ROL desconocido: $ROL" >&2; exit 1 ;;
esac
