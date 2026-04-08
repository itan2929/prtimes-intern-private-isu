#!/bin/sh
set -eu

mkdir -p /home/public/image
chown -R www-data:www-data /home/public

exec docker-php-entrypoint "$@"
