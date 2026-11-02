#!/usr/bin/env bash
# Dev tool: a public HTTPS URL that records whatever is sent to it, to see
# Quickshipper's webhook calls on a local machine.
#
#   ./run.sh start   # capture server + Cloudflare quick tunnel; prints the public URL
#   ./run.sh url     # print the public URL again
#   ./run.sh show    # print the captured requests (captured/requests.jsonl)
#   ./run.sh stop    # remove both containers (captured requests are kept)
#
# Only the capture server is published — never the store: the tunnel points
# at a separate container on its own Docker network. Quick tunnels need no
# Cloudflare account; the URL (https://<random>.trycloudflare.com) changes on
# every start, so re-register the webhook after a restart.
# CAPTURE_STATUS=503 ./run.sh start answers every call with 503 (to see retries).
# FORWARD_TO=http://localhost:8080 FORWARD_NETWORK=<stack network> ./run.sh start
# also passes POST /qshpr/webhook/status on to the store (and only that path):
# the capture server joins the stack's network, the tunnel does not.
set -euo pipefail
cd "$(dirname "$0")"

NET=qshpr-watch
CAPTURE=qshpr-watch-capture
TUNNEL=qshpr-watch-tunnel
PHP_IMAGE=php:8.4-cli-alpine
TUNNEL_IMAGE=cloudflare/cloudflared:2026.9.3

url() {
  docker logs "$TUNNEL" 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1
}

case "${1:-}" in
  start)
    mkdir -p captured
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
    docker rm -f "$CAPTURE" "$TUNNEL" >/dev/null 2>&1 || true
    docker run -d --name "$CAPTURE" --network "$NET" --user "$(id -u):$(id -g)" \
      -e CAPTURE_STATUS="${CAPTURE_STATUS:-200}" -e FORWARD_TO="${FORWARD_TO:-}" -v "$PWD:/watch" -w /watch \
      "$PHP_IMAGE" php -S 0.0.0.0:8080 capture.php >/dev/null
    if [ -n "${FORWARD_NETWORK:-}" ]; then
      docker network connect "$FORWARD_NETWORK" "$CAPTURE"
    fi
    docker run -d --name "$TUNNEL" --network "$NET" "$TUNNEL_IMAGE" \
      tunnel --no-autoupdate --url "http://$CAPTURE:8080" >/dev/null
    for _ in $(seq 1 30); do
      u="$(url || true)"
      if [ -n "$u" ]; then echo "$u"; exit 0; fi
      sleep 1
    done
    echo "tunnel did not report a URL; see: docker logs $TUNNEL" >&2
    exit 1
    ;;
  url) url ;;
  show) cat captured/requests.jsonl 2>/dev/null || echo "nothing captured yet" ;;
  stop)
    docker rm -f "$CAPTURE" "$TUNNEL" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    ;;
  *) echo "usage: $0 start|url|show|stop" >&2; exit 2 ;;
esac
