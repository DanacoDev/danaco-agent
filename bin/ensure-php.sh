#!/usr/bin/env bash
# Install the PHP packages Danaco needs. Exit 10 when packages were installed, 0 when they were already present.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
bash "$ROOT/bin/save-github-credentials.sh" "$ROOT" || true

PACKAGES=(php-cli php-sqlite3 sqlite3 php-curl php-mbstring php-xml php-zip)

php_has() {
  # grep -q in a pipeline makes php exit early, and pipefail then treats that as failure.
  grep -Eiq "^$1$" <<<"$PHP_MODULES"
}

missing_php() {
  local missing=()
  if ! command -v php >/dev/null 2>&1; then
    printf '%s\n' "PHP 8.2+"
    return 0
  fi
  php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || missing+=("PHP 8.2 or newer")
  PHP_MODULES="$(php -m 2>/dev/null || true)"
  grep -Eiq 'pdo_sqlite|sqlite3' <<<"$PHP_MODULES" || missing+=("pdo_sqlite")
  command -v sqlite3 >/dev/null 2>&1 || missing+=("sqlite3 command-line tool")
  php_has curl || missing+=("curl")
  php_has openssl || missing+=("openssl")
  php_has mbstring || missing+=("mbstring")
  php_has dom || missing+=("dom (DOMDocument)")
  php_has zip || missing+=("zip")
  if [[ ${#missing[@]} -eq 0 ]]; then
    return 1
  fi
  printf '%s\n' "${missing[*]}"
}

run_root() {
  if [[ "$(id -u)" -eq 0 ]]; then
    "$@"
    return
  fi
  if sudo -n true 2>/dev/null; then
    sudo -n "$@"
    return
  fi
  if [[ -t 0 ]]; then
    sudo "$@"
    return
  fi
  return 1
}

install_packages() {
  local os
  os="$(uname -s)"
  if [[ "$os" == "Linux" ]] && command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    run_root apt-get update
    run_root apt-get install -y "${PACKAGES[@]}"
    return
  fi
  if [[ "$os" == "Darwin" ]] && command -v brew >/dev/null 2>&1; then
    brew install php sqlite
    return
  fi
  echo "Install ${PACKAGES[*]}, then run this again." >&2
  return 1
}

missing="$(missing_php || true)"
if [[ -z "$missing" ]]; then
  exit 0
fi

echo "PHP is missing: $missing"
if ! install_packages; then
  echo "Could not install the PHP packages. On Ubuntu, run: sudo apt-get install -y ${PACKAGES[*]}" >&2
  exit 1
fi

missing="$(missing_php || true)"
if [[ -n "$missing" ]]; then
  echo "PHP is still missing: $missing" >&2
  exit 1
fi

echo "Installed the PHP and SQLite packages Danaco needs, including the sqlite3 command-line tool."
exit 10
