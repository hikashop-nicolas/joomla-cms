#!/usr/bin/env bash
# Reset the customize-mode demo to a clean state from the baked fork image.
#
# Use this after someone breaks the live site, e.g. by running System > Joomla!
# Update in the admin, which overwrites the forked Joomla core with stock files
# and strips out the customize integration (the Customize button disappears and
# pages stop emitting the data-customize markup).
#
#   ./reset-demo.sh            quick reset: recreate from the current image, re-seed
#   ./reset-demo.sh --rebuild  rebuild the image from the latest branch first
#                              (use this to move the demo from 6.1 to 6.2)
#
# Note: the webroot lives in the joomla_data volume and the entrypoint only
# repopulates it when empty, so a plain restart will NOT undo a core overwrite.
# This script tears the volumes down (-v) so the image is the source of truth again.
set -euo pipefail

cd "$(dirname "$0")"

# docker compose v2 plugin, or legacy docker-compose binary
if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  DC="docker-compose"
else
  echo "error: neither 'docker compose' nor 'docker-compose' is available" >&2
  exit 1
fi

REBUILD=0
case "${1:-}" in
  --rebuild|-b) REBUILD=1 ;;
  "") ;;
  *) echo "usage: $0 [--rebuild]" >&2; exit 2 ;;
esac

# Host port the stack is published on (defaults match docker-compose.yml).
PORT="$(grep -E '^HTTP_PORT=' .env 2>/dev/null | cut -d= -f2 || true)"
PORT="${PORT:-8080}"

echo "==> Tearing down the stack (drops the webroot AND database volumes)..."
$DC down -v

if [ "$REBUILD" -eq 1 ]; then
  echo "==> Rebuilding the image from the latest branch (no cache)..."
  $DC build --no-cache
fi

echo "==> Starting the stack..."
$DC up -d

echo -n "==> Waiting for Joomla to install and seed"
ready=0
for _ in $(seq 1 120); do
  if curl -fsS "http://localhost:${PORT}/" >/dev/null 2>&1; then ready=1; echo " up"; break; fi
  echo -n "."
  sleep 4
done
[ "$ready" -eq 1 ] || echo " (timed out waiting for HTTP; check '$DC logs joomla')"

echo "==> Verifying the forked core is active..."
if curl -fsS "http://localhost:${PORT}/?customize=1" 2>/dev/null | grep -q 'customize-mode:active'; then
  echo "    OK: customize-mode:active marker present, the fork is live."
else
  echo "    WARNING: marker NOT found. The core may still be stock; see '$DC logs joomla'." >&2
fi

echo
echo "Done. Open /administrator and confirm the Customize button is back on a menu item."
