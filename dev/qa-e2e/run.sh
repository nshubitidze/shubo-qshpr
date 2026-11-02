#!/usr/bin/env bash
# Run a browser test inside the pinned Playwright image (its browsers must
# match the npm playwright version, 1.54.0). node_modules stays in this folder.
#
#   BASE_URL=http://localhost:8080 PRODUCT_ID=1 ./run.sh checkout-rates.js
#   BASE_URL=… ADMIN_PATH=… ADMIN_USER=… ADMIN_PASSWORD=… ORDER_NUMBER=… ./run.sh admin-ship.js
#
# BASE_URL's hostname is mapped to 127.0.0.1 inside the container, which uses
# the host network, so a local store on a custom hostname works.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p shots
BASE_URL="${BASE_URL:?set BASE_URL to the store URL, e.g. http://localhost:8080}"
HOST="$(echo "$BASE_URL" | sed -E 's#^[a-z]+://([^/:]+).*#\1#')"
docker run --rm --network host --add-host "$HOST:127.0.0.1" \
  -v "$PWD:/work" -w /work \
  -e BASE_URL="$BASE_URL" -e PRODUCT_ID="${PRODUCT_ID:-1}" -e RATES_ONLY="${RATES_ONLY:-}" \
  -e COURIER="${COURIER:-}" -e ADMIN_PATH="${ADMIN_PATH:-}" -e ADMIN_USER="${ADMIN_USER:-}" \
  -e ADMIN_PASSWORD="${ADMIN_PASSWORD:-}" -e ORDER_NUMBER="${ORDER_NUMBER:-}" -e BUTTON="${BUTTON:-}" -e LOGOS="${LOGOS:-}" -e SCHEDULE="${SCHEDULE:-}" \
  -e EXPECT_HIDDEN="${EXPECT_HIDDEN:-}" \
  -e COD="${COD:-}" \
  -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
  mcr.microsoft.com/playwright:v1.54.0-noble \
  bash -c 'test -d node_modules/playwright || npm i --no-audit --no-fund >/dev/null 2>&1;
    node '"$1"'; rc=$?
    # The image runs as root: hand the artefacts back to the invoking user.
    chown -R "$HOST_UID:$HOST_GID" node_modules package-lock.json shots 2>/dev/null
    exit $rc'
