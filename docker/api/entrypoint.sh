#!/bin/sh
# Nginx runs alongside PHP-FPM in the api image; PHP-FPM stays PID 1 so the
# container's STOPSIGNAL (SIGQUIT) reaches it directly and drains gracefully.
set -e

# sys_temp_dir and the FPM socket live here; a tmpfs mount would start it empty.
mkdir -p /tmp/kanso 2>/dev/null || true

if [ "$1" = "php-fpm" ]; then
    # -e: nginx opens its compiled-in error log before reading the config.
    nginx -e /dev/stderr -g 'daemon on;'
    trap 'nginx -s quit 2>/dev/null || true' TERM QUIT
fi
exec "$@"
