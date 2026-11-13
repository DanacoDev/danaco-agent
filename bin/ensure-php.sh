#!/usr/bin/env bash
# Install the PHP packages Danaco needs, including bubblewrap and pip for run_python. Exit 10 when packages were installed, 0 when they were already present.
set -euo pipefail

# The graphical installer is an AppImage. Its Python is first on PATH and has no pip.
unset PYTHONHOME PYTHONPATH PYTHONNOUSERSITE || true
unset LD_LIBRARY_PATH GI_TYPELIB_PATH GDK_PIXBUF_MODULEDIR GDK_PIXBUF_MODULE_FILE || true
if [[ -n "${APPDIR:-}" && -n "${PATH:-}" ]]; then
  bundled_bin="${APPDIR%/}/usr/bin"
  PATH=":${PATH}:"
  PATH="${PATH//:$bundled_bin:/:}"
  PATH="${PATH#:}"
  PATH="${PATH%:}"
  export PATH
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
bash "$ROOT/bin/save-github-credentials.sh" "$ROOT" || true

PACKAGES=(php-cli php-sqlite3 sqlite3 php-curl php-mbstring php-xml php-zip bubblewrap python3 python3-pip)
FEDORA_PACKAGES=(php-cli php-pdo php-sqlite3 sqlite bubblewrap python3 python3-pip)
ARCH_PACKAGES=(php sqlite bubblewrap python python-pip)

php_has() {
  # grep -q in a pipeline makes php exit early, and pipefail then treats that as failure.
  grep -Eiq "^$1$" <<<"$PHP_MODULES"
}

distro_pip_installed() {
  local status
  if command -v apt-get >/dev/null 2>&1; then
    status="$(dpkg-query -W -f='${Status}' python3-pip 2>/dev/null || true)"
    if [[ "$status" == "install ok installed" ]]; then
      return 0
    fi
    return 1
  fi
  if command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    if rpm -q python3-pip >/dev/null 2>&1; then
      return 0
    fi
    return 1
  fi
  if command -v pacman >/dev/null 2>&1; then
    if pacman -Q python-pip >/dev/null 2>&1; then
      return 0
    fi
    return 1
  fi
  return 1
}

system_pip_runs() {
  local candidate
  for candidate in /usr/bin/python3 /usr/bin/pip3; do
    [[ -x "$candidate" ]] || continue
    if [[ "$candidate" == */pip3 ]]; then
      env -u PYTHONHOME -u PYTHONPATH -u PYTHONNOUSERSITE -u LD_LIBRARY_PATH "$candidate" --version >/dev/null 2>&1 && return 0
    else
      env -u PYTHONHOME -u PYTHONPATH -u PYTHONNOUSERSITE -u LD_LIBRARY_PATH "$candidate" -m pip --version >/dev/null 2>&1 && return 0
    fi
  done
  return 1
}

pip_ready() {
  if distro_pip_installed; then
    return 0
  fi
  system_pip_runs
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
  if [[ "$(uname -s)" == "Linux" ]]; then
    if ! command -v bwrap >/dev/null 2>&1; then
      missing+=("bubblewrap")
    fi
    if ! pip_ready; then
      missing+=("python3-pip")
    fi
  fi
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
  if command -v pkexec >/dev/null 2>&1; then
    pkexec env DEBIAN_FRONTEND="${DEBIAN_FRONTEND:-noninteractive}" "$@"
    return
  fi
  return 1
}

install_packages() {
  local os package_manager
  os="$(uname -s)"
  if [[ "$os" == "Linux" ]] && command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    # One root prompt for both apt steps. The variable is set again inside the
    # root shell because sudo and pkexec drop it.
    run_root bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y "$@"' bash "${PACKAGES[@]}" || return 1
    return
  fi
  if [[ "$os" == "Linux" ]] && command -v dnf >/dev/null 2>&1; then
    run_root dnf install -y "${FEDORA_PACKAGES[@]}" || return 1
    return
  fi
  if [[ "$os" == "Linux" ]] && command -v pacman >/dev/null 2>&1; then
    run_root pacman -Sy --needed --noconfirm "${ARCH_PACKAGES[@]}" || return 1
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
  echo "Could not install the PHP packages. Install PHP, SQLite, bubblewrap, and pip with this system's package manager, then run this again." >&2
  exit 1
fi

missing="$(missing_php || true)"
if [[ -n "$missing" ]]; then
  echo "PHP is still missing: $missing" >&2
  exit 1
fi

echo "Installed the PHP, SQLite, and bubblewrap packages Danaco needs."
exit 10
