#!/bin/sh
# ROL=web (por defecto) | horizon | scheduler | migrar
set -e
cd /var/www/html
php artisan config:cache && php artisan route:cache && php artisan view:cache
case "${ROL:-web}" in
  web)       exec supervisord -c /etc/supervisord.conf ;;
  horizon)   exec php artisan horizon ;;
  scheduler) exec php artisan schedule:work ;;
  migrar)    exec php artisan migrate --force ;;
  *) echo "ROL desconocido: $ROL" >&2; exit 1 ;;
esac
