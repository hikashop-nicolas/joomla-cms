#!/bin/bash
# First-run setup for the customize-mode demo: populate the webroot from the image,
# wait for MySQL, install Joomla non-interactively, then seed demo content. Idempotent:
# once configuration.php exists it just serves.
set -e

SRC=/usr/src/joomla
WEB=/var/www/html

if [ ! -f "$WEB/libraries/src/Factory.php" ]; then
  echo "[entrypoint] populating webroot from image..."
  cp -a "$SRC/." "$WEB/"
fi
cd "$WEB"

echo "[entrypoint] waiting for database at ${JOOMLA_DB_HOST}..."
until php -r '@mysqli_connect(getenv("JOOMLA_DB_HOST"), getenv("JOOMLA_DB_USER"), getenv("JOOMLA_DB_PASSWORD"), getenv("JOOMLA_DB_NAME")) or exit(1);' 2>/dev/null; do
  sleep 2
done
echo "[entrypoint] database is up."

if [ ! -f "$WEB/configuration.php" ]; then
  echo "[entrypoint] installing Joomla..."
  php installation/joomla.php install -n \
    --site-name="${JOOMLA_SITE_NAME}" \
    --admin-user="Demo Admin" \
    --admin-username="${JOOMLA_ADMIN_USER}" \
    --admin-password="${JOOMLA_ADMIN_PASSWORD}" \
    --admin-email="${JOOMLA_ADMIN_EMAIL}" \
    --db-type=mysqli \
    --db-host="${JOOMLA_DB_HOST}" \
    --db-user="${JOOMLA_DB_USER}" \
    --db-pass="${JOOMLA_DB_PASSWORD}" \
    --db-name="${JOOMLA_DB_NAME}" \
    --db-prefix=jcz_

  # The CLI installer leaves the installation folder behind; Joomla refuses to run until it is gone.
  rm -rf "$WEB/installation"

  echo "[entrypoint] seeding demo content..."
  php /demo/seed.php || echo "[entrypoint] seed step failed (continuing without it)"
fi

chown -R www-data:www-data "$WEB"
echo "[entrypoint] ready; starting: $*"
exec "$@"
