#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be a number." >&2
        exit 1
        ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${port}>/" /etc/apache2/sites-available/000-default.conf

upload_dir=/var/www/html/assets/uploads
mkdir -p "$upload_dir"
if [ -z "$(ls -A "$upload_dir")" ]; then
    cp -a /opt/seed-uploads/. "$upload_dir"/
    chown -R www-data:www-data "$upload_dir"
fi
chown www-data:www-data "$upload_dir"

exec apache2-foreground
