#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then
  ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fi

command -v gh >/dev/null 2>&1 || exit 0
if command -v timeout >/dev/null 2>&1; then
  token="$(timeout 5 gh auth token 2>/dev/null || true)"
else
  token="$(gh auth token 2>/dev/null || true)"
fi
case "$token" in
  *[!A-Za-z0-9_]*) exit 0 ;;
esac
if [[ ${#token} -lt 20 || ${#token} -gt 300 ]]; then
  exit 0
fi
dest="$ROOT/data/.github-credentials"
mkdir -p "$ROOT/data"
(umask 077; printf '%s\n' "$token" > "$dest")
chmod 600 "$dest" 2>/dev/null || true
