#!/usr/bin/env bash
# Create a local certificate authority and a server certificate for this machine.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/data/tls"
mkdir -p "$DIR"
chmod 700 "$DIR"

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to create the local certificate." >&2
  exit 1
fi

safe_dns() {
  local name
  name="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9.-' '-')"
  name="${name#-}"
  name="${name%-}"
  if [[ -n "$name" && "$name" != "-" ]]; then
    printf '%s' "$name"
  fi
}

collect_names() {
  local host short local_name ip
  printf '%s\n' 'DNS:localhost' 'IP:127.0.0.1'
  short="$(safe_dns "$(hostname -s 2>/dev/null || hostname 2>/dev/null || true)")"
  if [[ -n "$short" && "$short" != "localhost" ]]; then
    printf 'DNS:%s\n' "$short"
  fi
  host="$(safe_dns "$(hostname -f 2>/dev/null || true)")"
  if [[ -n "$host" && "$host" != "localhost" && "$host" != "$short" && "$host" != *.* ]]; then
    :
  fi
  if [[ -n "$host" && "$host" == *.* && "$host" != "localhost" ]]; then
    printf 'DNS:%s\n' "$host"
  fi
  if command -v scutil >/dev/null 2>&1; then
    local_name="$(safe_dns "$(scutil --get LocalHostName 2>/dev/null || true)")"
    if [[ -n "$local_name" ]]; then
      printf 'DNS:%s.local\n' "$local_name"
    fi
  fi
  if command -v ip >/dev/null 2>&1; then
    while IFS= read -r ip; do
      [[ "$ip" == 127.* ]] && continue
      printf 'IP:%s\n' "$ip"
    done < <(ip -4 -o addr show scope global 2>/dev/null | awk '{ split($4, a, "/"); print a[1] }')
  fi
  if command -v ifconfig >/dev/null 2>&1; then
    while IFS= read -r ip; do
      [[ "$ip" == 127.* ]] && continue
      printf 'IP:%s\n' "$ip"
    done < <(ifconfig -a 2>/dev/null | awk '/inet / { print $2 }')
  fi
}

NAMES="$(collect_names | awk 'NF && !seen[$0]++')"
printf '%s\n' "$NAMES" > "$DIR/san.list.new"

need_server=0
if [[ ! -f "$DIR/ca.crt" || ! -f "$DIR/ca.key" ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout "$DIR/ca.key" \
    -out "$DIR/ca.crt" \
    -days 3650 \
    -subj "/CN=Danaco Local CA" \
    -addext "basicConstraints=critical,CA:TRUE" >/dev/null 2>&1 \
    || openssl req -x509 -newkey rsa:2048 -nodes \
      -keyout "$DIR/ca.key" \
      -out "$DIR/ca.crt" \
      -days 3650 \
      -subj "/CN=Danaco Local CA" >/dev/null 2>&1
  chmod 600 "$DIR/ca.key"
  need_server=1
fi

if [[ ! -f "$DIR/server.crt" || ! -f "$DIR/server.key" ]]; then
  need_server=1
elif ! cmp -s "$DIR/san.list.new" "$DIR/san.list" 2>/dev/null; then
  need_server=1
elif ! openssl x509 -checkend 2592000 -noout -in "$DIR/server.crt" >/dev/null 2>&1; then
  need_server=1
fi

if [[ "$need_server" == "1" ]]; then
  san_csv="$(printf '%s\n' "$NAMES" | paste -sd, -)"
  cat > "$DIR/server.ext" <<EOF
basicConstraints=CA:FALSE
keyUsage=digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=${san_csv}
EOF
  openssl req -new -newkey rsa:2048 -nodes \
    -keyout "$DIR/server.key" \
    -out "$DIR/server.csr" \
    -subj "/CN=localhost" >/dev/null 2>&1
  openssl x509 -req \
    -in "$DIR/server.csr" \
    -CA "$DIR/ca.crt" \
    -CAkey "$DIR/ca.key" \
    -CAcreateserial \
    -out "$DIR/server.crt" \
    -days 397 \
    -sha256 \
    -extfile "$DIR/server.ext" >/dev/null 2>&1
  chmod 600 "$DIR/server.key"
  rm -f "$DIR/server.csr"
fi

mv "$DIR/san.list.new" "$DIR/san.list"
chmod 600 "$DIR/ca.key" "$DIR/server.key"
chmod 644 "$DIR/ca.crt" "$DIR/server.crt"
