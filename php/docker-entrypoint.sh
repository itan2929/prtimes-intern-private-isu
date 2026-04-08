#!/bin/sh
set -eu

mkdir -p /home/public/image
chown www-data:www-data /home/public/image

exec docker-php-entrypoint "$@"
