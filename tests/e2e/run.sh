#!/usr/bin/env bash
# End-to-end tests: starts WordPress in Docker with WP MCP Connector mounted
# read-only, drives the MCP endpoint and the OAuth server over HTTP, then
# removes the containers.
#   tests/e2e/run.sh          run and clean up
#   KEEP=1 tests/e2e/run.sh   leave the site running at http://localhost:8090
#   PLUGIN_DIR=/path/to/checkout tests/e2e/run.sh   test another copy of the plugin
set -euo pipefail
cd "$(dirname "$0")"
export PLUGIN_DIR="${PLUGIN_DIR:-$(cd ../.. && pwd)}"  # override to test another checkout
BASE=http://localhost:8090
compose() { docker compose -f compose.yml -p wpmcp-e2e "$@"; }
wp() { compose run --rm -T cli "$@" </dev/null | tr -d '\r'; }  # </dev/null: run would eat stdin

[ "${KEEP:-}" = 1 ] || trap 'compose down -v >/dev/null 2>&1' EXIT
compose up -d --quiet-pull wordpress
for i in $(seq 1 60); do
  curl -fs -o /dev/null "$BASE/wp-login.php" && break
  curl -s -o /dev/null -w '%{http_code}' "$BASE/" | grep -qE '200|302' && break
  sleep 2
done
wp core is-installed 2>/dev/null || wp core install --url="$BASE" --title="WPMCP E2E" --admin_user=admin \
  --admin_password="$(head -c 24 /dev/urandom | base64)" --admin_email=admin@example.test --skip-email
# Pretty permalinks so /wp-json/ and the /mcp-oauth/ and /.well-known/ paths
# reach WordPress (the image ships the standard rewrite .htaccess).
wp rewrite structure '/%postname%/' >/dev/null
wp plugin activate wp-mcp-connector

WPMCP_BASE="$BASE" php e2e.php
