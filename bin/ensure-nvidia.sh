#!/usr/bin/env bash
# If the NVIDIA driver has locked the GPU, install the next series of the same kind.
# Open stays open, server stays server. Exit 1 only when that install fails.
set -euo pipefail

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
    pkexec env DEBIAN_FRONTEND="${DEBIAN_FRONTEND:-noninteractive}" NEEDRESTART_MODE=a "$@"
    return
  fi
  return 1
}

if [[ "$(uname -s)" != "Linux" ]] || ! command -v apt-get >/dev/null 2>&1 || ! command -v apt-cache >/dev/null 2>&1 || ! command -v dpkg-query >/dev/null 2>&1; then
  exit 0
fi

running_version() {
  local line version=""
  if [[ -r /proc/driver/nvidia/version ]]; then
    line="$(head -n 1 /proc/driver/nvidia/version 2>/dev/null || true)"
    version="$(printf '%s\n' "$line" | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | awk 'NR == 1 { print; exit }' || true)"
  fi
  if [[ -z "$version" ]] && command -v nvidia-smi >/dev/null 2>&1; then
    if command -v timeout >/dev/null 2>&1; then
      version="$(timeout 8 nvidia-smi --query-gpu=driver_version --format=csv,noheader,nounits 2>/dev/null | awk 'NR == 1 { gsub(/ /, "", $0); print; exit }' || true)"
    else
      version="$(nvidia-smi --query-gpu=driver_version --format=csv,noheader,nounits 2>/dev/null | awk 'NR == 1 { gsub(/ /, "", $0); print; exit }' || true)"
    fi
  fi
  if [[ ! "$version" =~ ^[0-9]+\.[0-9]+ ]]; then
    return 1
  fi
  printf '%s' "$version"
}

boot_text() {
  local boot="$1" text=""
  if command -v journalctl >/dev/null 2>&1; then
    if command -v timeout >/dev/null 2>&1; then
      text="$(timeout 10 journalctl -k -b "$boot" --no-pager -o cat 2>/dev/null || true)"
    else
      text="$(journalctl -k -b "$boot" --no-pager -o cat 2>/dev/null || true)"
    fi
  fi
  if [[ -z "$text" && "$boot" == "0" ]] && command -v dmesg >/dev/null 2>&1; then
    if command -v timeout >/dev/null 2>&1; then
      text="$(timeout 5 dmesg 2>/dev/null || true)"
    else
      text="$(dmesg 2>/dev/null || true)"
    fi
  fi
  printf '%s\n' "$text"
}

boot_bad() {
  local text version major
  text="$(boot_text "$1")"
  [[ -n "$text" ]] || return 1
  if ! grep -Eq 'nvidia-modeset: ERROR:.*Error while waiting for GPU progress|NVRM:.*GPU has fallen off the bus|NVRM: Xid \(PCI:[^)]*\): (79|109),' <<<"$text"; then
    return 1
  fi
  version="$(printf '%s\n' "$text" | grep -E 'Kernel Module' | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | awk 'END { print }' || true)"
  if [[ -z "$version" ]]; then
    [[ "$1" == "0" ]]
    return
  fi
  major="${version%%.*}"
  [[ "$major" == "$SERIES" ]]
}

current_package() {
  local pkg open=0
  local -a same=() filtered=()
  if [[ -r /proc/driver/nvidia/version ]] && grep -q 'Open Kernel Module' /proc/driver/nvidia/version; then
    open=1
  fi
  while IFS= read -r pkg; do
    [[ -n "$pkg" ]] || continue
    if [[ "$pkg" =~ ^nvidia-driver-${SERIES}(-server)?(-open)?$ ]]; then
      same+=("$pkg")
    fi
  done < <(dpkg-query -W -f '${Status} ${Package}\n' 'nvidia-driver-*' 2>/dev/null | awk '$1 == "install" && $2 == "ok" && $3 == "installed" { print $4 }' || true)
  if [[ ${#same[@]} -eq 0 ]]; then
    return 1
  fi
  if [[ ${#same[@]} -eq 1 ]]; then
    printf '%s' "${same[0]}"
    return 0
  fi
  for pkg in "${same[@]}"; do
    if [[ "$open" -eq 1 ]]; then
      [[ "$pkg" == *-open ]] && filtered+=("$pkg")
    else
      [[ "$pkg" != *-open ]] && filtered+=("$pkg")
    fi
  done
  if [[ ${#filtered[@]} -eq 1 ]]; then
    printf '%s' "${filtered[0]}"
    return 0
  fi
  echo "The NVIDIA driver ${VERSION} locked the GPU. ${same[*]} are all installed, so nothing was changed." >&2
  return 2
}

next_package() {
  local suffix="$1" name num best="" best_num=""
  while IFS= read -r name; do
    [[ "$name" =~ ^nvidia-driver-([0-9]+)${suffix}$ ]] || continue
    num="${BASH_REMATCH[1]}"
    [[ "$num" =~ ^[0-9]+$ && "$num" -gt "$SERIES" ]] || continue
    apt-cache show "$name" >/dev/null 2>&1 || continue
    if [[ -z "$best_num" || "$num" -lt "$best_num" ]]; then
      best="$name"
      best_num="$num"
    fi
  done < <(apt-cache pkgnames nvidia-driver- || true)
  [[ -n "$best" ]] || return 1
  printf '%s' "$best"
}

package_installed() {
  local status
  status="$(dpkg-query -W -f '${Status}' "$1" 2>/dev/null || true)"
  [[ "$status" == "install ok installed" ]]
}

VERSION="$(running_version || true)"
[[ -n "${VERSION:-}" ]] || exit 0
SERIES="${VERSION%%.*}"
[[ "$SERIES" =~ ^[0-9]+$ ]] || exit 0

if ! boot_bad 0 && ! boot_bad -1; then
  exit 0
fi

code=0
CURRENT="$(current_package)" || code=$?
if [[ "$code" -eq 2 ]]; then
  exit 0
fi
if [[ "$code" -ne 0 || -z "${CURRENT:-}" ]]; then
  echo "The NVIDIA driver ${VERSION} locked the GPU. No apt package for this series is installed, so nothing was changed." >&2
  exit 0
fi

SUFFIX="${CURRENT#nvidia-driver-${SERIES}}"
NEXT="$(next_package "$SUFFIX" || true)"
if [[ -z "$NEXT" ]]; then
  export DEBIAN_FRONTEND=noninteractive
  if ! run_root bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update'; then
    echo "The NVIDIA driver ${VERSION} locked the GPU. Could not refresh the package list." >&2
    exit 1
  fi
  NEXT="$(next_package "$SUFFIX" || true)"
fi
if [[ -z "$NEXT" ]]; then
  echo "The NVIDIA driver ${VERSION} locked the GPU. No newer package of the same kind as ${CURRENT} is available, so it was left as it is." >&2
  exit 0
fi
if package_installed "$NEXT"; then
  echo "The NVIDIA driver ${VERSION} locked the GPU. ${NEXT} is already installed. Reboot to load it."
  exit 0
fi

echo "The NVIDIA driver ${VERSION} locked the GPU."
echo "Installing the next driver of the same kind: ${NEXT}"
export DEBIAN_FRONTEND=noninteractive
if ! run_root bash -c 'export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a; apt-get update && apt-get install -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$1"' bash "$NEXT"; then
  echo "Could not install ${NEXT}. On Ubuntu, run: sudo apt-get install -y ${NEXT}" >&2
  exit 1
fi
if ! package_installed "$NEXT"; then
  echo "Could not install ${NEXT}. On Ubuntu, run: sudo apt-get install -y ${NEXT}" >&2
  exit 1
fi
echo "Installed ${NEXT}. Reboot to load it."
exit 0
