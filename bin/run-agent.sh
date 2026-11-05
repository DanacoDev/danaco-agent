#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
mkdir -p "$ROOT/data/sessions"
export PATH="/opt/homebrew/bin:/usr/local/bin:${PATH:-/usr/bin:/bin}"

bash "$ROOT/bin/save-github-credentials.sh" "$ROOT" || true

find_php() {
  local candidate
  for candidate in /opt/homebrew/bin/php /usr/local/bin/php; do
    if [[ -x "$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
  done
  if command -v php >/dev/null 2>&1; then
    command -v php
    return 0
  fi
  return 1
}

PHP="$(find_php || true)"
if [[ -z "$PHP" ]]; then
  echo "php not found" >&2
  exit 1
fi

BIND="$("$PHP" -r 'require "src/bootstrap.php"; $b = (string) Database::instance()->getSetting("bind_host", "127.0.0.1"); echo $b === "0.0.0.0" ? "0.0.0.0" : "127.0.0.1";' 2>/dev/null || true)"
if [[ "$BIND" != "0.0.0.0" && "$BIND" != "127.0.0.1" ]]; then
  BIND="127.0.0.1"
fi
PORT="$("$PHP" -r 'require "src/bootstrap.php"; $p = (string) Database::instance()->getSetting("port", ""); if ($p === "" || !ctype_digit($p)) { $p = (string) (app_config()["port"] ?? 8000); } echo (int) $p;' 2>/dev/null || true)"
if [[ ! "$PORT" =~ ^[0-9]+$ ]]; then
  PORT=8000
fi

port_busy() {
  local port="$1"
  if command -v lsof >/dev/null 2>&1 && lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
    return 0
  fi
  return 1
}

backend_port() {
  local candidate
  for candidate in $(seq $((PORT + 1000)) $((PORT + 1100))); do
    if [[ "$candidate" -gt 65535 ]]; then
      break
    fi
    if ! port_busy "$candidate"; then
      echo "$candidate"
      return 0
    fi
  done
  return 1
}

PHP_PID=""
TICK_PID=""
PROXY_PID=""
cleanup() {
  trap - EXIT INT TERM
  local pid
  if [[ -n "$TICK_PID" ]]; then
    pkill -P "$TICK_PID" 2>/dev/null || true
  fi
  for pid in "$PROXY_PID" "$PHP_PID" "$TICK_PID"; do
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
    fi
  done
  wait "$PROXY_PID" "$PHP_PID" "$TICK_PID" 2>/dev/null || true
  rm -f "$ROOT/data/server.pid" "$ROOT/data/tick.pid"
}
trap cleanup EXIT INT TERM

(
  while true; do
    "$PHP" "$ROOT/bin/tick.php" >> "$ROOT/data/tick.log" 2>&1 || true
    sleep 30
  done
) &
TICK_PID=$!
echo "$TICK_PID" > "$ROOT/data/tick.pid"

export PHP_CLI_SERVER_WORKERS=4
TLS_DIR="$ROOT/data/tls"
if bash "$ROOT/bin/ensure-tls.sh" >> "$ROOT/data/server.log" 2>&1 && [[ -f "$TLS_DIR/server.crt" && -f "$TLS_DIR/server.key" ]]; then
  BACKEND="$(backend_port || true)"
  if [[ -z "$BACKEND" ]]; then
    echo "No free port for the local web server." >> "$ROOT/data/server.log"
    exit 1
  fi
  "$PHP" -d upload_max_filesize=2048M -d post_max_size=2048M -S "127.0.0.1:${BACKEND}" -t public public/index.php >> "$ROOT/data/server.log" 2>&1 &
  PHP_PID=$!
  "$PHP" "$ROOT/bin/tls-proxy.php" "$BIND" "$PORT" "$TLS_DIR/server.crt" "$TLS_DIR/server.key" "127.0.0.1" "$BACKEND" >> "$ROOT/data/server.log" 2>&1 &
  PROXY_PID=$!
  echo "$PROXY_PID" > "$ROOT/data/server.pid"
  while kill -0 "$PHP_PID" 2>/dev/null && kill -0 "$PROXY_PID" 2>/dev/null; do
    sleep 1
  done
else
  echo "The local certificate could not be created. Starting without encryption." >> "$ROOT/data/server.log"
  "$PHP" -d upload_max_filesize=2048M -d post_max_size=2048M -S "${BIND}:${PORT}" -t public public/index.php >> "$ROOT/data/server.log" 2>&1 &
  PHP_PID=$!
  echo "$PHP_PID" > "$ROOT/data/server.pid"
  wait "$PHP_PID" || true
fi
