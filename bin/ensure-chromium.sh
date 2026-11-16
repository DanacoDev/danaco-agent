#!/usr/bin/env bash
# Install a real browser for screenshots and the headless browser, and poppler for scanned PDFs.
# A snap build does not count: systemd cannot start it. Exit 10 when something was installed, 0 when both were already present.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

is_real_browser() {
  local path="$1"
  local head
  [[ -n "$path" && -x "$path" ]] || return 1
  [[ "$path" == *"/snap/"* ]] && return 1
  head="$(head -c 4000 "$path" 2>/dev/null || true)"
  if [[ "$head" == "#!"* && "$head" == *"snap"* ]]; then
    return 1
  fi
  return 0
}

real_browser() {
  local name path owned
  for owned in "$ROOT"/data/browser/*/chrome-headless-shell "$ROOT"/data/browser/*/chrome; do
    if is_real_browser "$owned"; then
      return 0
    fi
  done
  for name in chromium chromium-browser google-chrome-stable google-chrome; do
    path="$(command -v "$name" 2>/dev/null || true)"
    if is_real_browser "$path"; then
      return 0
    fi
  done
  for path in \
    /usr/bin/chromium \
    /usr/bin/chromium-browser \
    /usr/bin/google-chrome-stable \
    /usr/bin/google-chrome
  do
    if is_real_browser "$path"; then
      return 0
    fi
  done
  if [[ "$(uname -s)" == "Darwin" ]]; then
    if [[ -x "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" || -x "/Applications/Chromium.app/Contents/MacOS/Chromium" ]]; then
      return 0
    fi
  fi
  return 1
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

have_pdftoppm() {
  command -v pdftoppm >/dev/null 2>&1 || command -v pdftocairo >/dev/null 2>&1
}

browser_url() {
  local json="$1" platform="$2"
  if command -v php >/dev/null 2>&1; then
    php -r '
      $data = json_decode((string)file_get_contents($argv[1]), true);
      $rows = $data["channels"]["Stable"]["downloads"]["chrome-headless-shell"] ?? [];
      foreach ($rows as $row) {
        if (($row["platform"] ?? "") === $argv[2] && !empty($row["url"])) {
          echo $row["url"];
          exit(0);
        }
      }
      exit(1);
    ' "$json" "$platform" && return 0
  fi
  if command -v python3 >/dev/null 2>&1; then
    python3 -c '
import json, sys
data = json.load(open(sys.argv[1]))
rows = data.get("channels", {}).get("Stable", {}).get("downloads", {}).get("chrome-headless-shell", [])
for row in rows:
    if row.get("platform") == sys.argv[2] and row.get("url"):
        print(row["url"])
        raise SystemExit(0)
raise SystemExit(1)
' "$json" "$platform" && return 0
  fi
  return 1
}

extract_zip() {
  local zip="$1" dest="$2"
  mkdir -p "$dest"
  if command -v unzip >/dev/null 2>&1; then
    unzip -q -o "$zip" -d "$dest"
    return
  fi
  if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' >/dev/null 2>&1; then
    php -r '$zip = new ZipArchive(); if ($zip->open($argv[1]) !== true) { exit(1); } $zip->extractTo($argv[2]); $zip->close();' "$zip" "$dest"
    return
  fi
  python3 -m zipfile -e "$zip" "$dest"
}

download_headless() {
  local platform url tmp unpacked folder
  case "$(uname -m)" in
    x86_64|amd64) platform="linux64" ;;
    aarch64|arm64) platform="linux-arm64" ;;
    *)
      echo "No headless browser build for $(uname -m)." >&2
      return 1
      ;;
  esac
  for unpacked in "$ROOT"/data/browser/*/chrome-headless-shell; do
    if is_real_browser "$unpacked"; then
      return 0
    fi
  done
  if ! command -v curl >/dev/null 2>&1; then
    echo "curl is missing, so the headless browser could not be downloaded." >&2
    return 1
  fi
  tmp="$(mktemp -d)"
  if ! curl -fsSL "https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json" -o "$tmp/versions.json"; then
    rm -rf "$tmp"
    echo "Could not download the headless browser list." >&2
    return 1
  fi
  if ! url="$(browser_url "$tmp/versions.json" "$platform")"; then
    rm -rf "$tmp"
    echo "Could not find a headless browser build for ${platform}." >&2
    return 1
  fi
  if ! curl -fL --retry 2 -o "$tmp/browser.zip" "$url"; then
    rm -rf "$tmp"
    echo "Could not download the headless browser." >&2
    return 1
  fi
  if ! extract_zip "$tmp/browser.zip" "$tmp/out"; then
    rm -rf "$tmp"
    echo "Could not unpack the headless browser." >&2
    return 1
  fi
  unpacked="$(find "$tmp/out" -type f -name chrome-headless-shell | head -n 1)"
  if [[ -z "$unpacked" ]]; then
    rm -rf "$tmp"
    echo "The headless browser package had no binary." >&2
    return 1
  fi
  folder="$(basename "$(dirname "$unpacked")")"
  mkdir -p "$ROOT/data/browser"
  rm -rf "$ROOT/data/browser/$folder"
  mv "$(dirname "$unpacked")" "$ROOT/data/browser/$folder"
  chmod +x "$ROOT/data/browser/$folder/chrome-headless-shell"
  rm -rf "$tmp"
  echo "Installed the headless browser."
}

has_candidate() {
  local candidate
  candidate="$(apt-cache policy "$1" 2>/dev/null | awk '/^  Candidate:/ {print $2; exit}')"
  [[ -n "$candidate" && "$candidate" != "(none)" ]]
}

install_browser_libs() {
  local pkg available=()
  local pkgs=(
    ca-certificates
    fonts-liberation
    libnss3
    libnspr4
    libatk1.0-0
    libatk1.0-0t64
    libatk-bridge2.0-0
    libatk-bridge2.0-0t64
    libcups2
    libcups2t64
    libdrm2
    libxkbcommon0
    libxcomposite1
    libxdamage1
    libxfixes3
    libxrandr2
    libgbm1
    libasound2
    libasound2t64
    libpango-1.0-0
    libcairo2
    libgtk-3-0
    libgtk-3-0t64
    libx11-6
    libx11-xcb1
    libxcb1
    libxext6
    libxshmfence1
  )
  for pkg in "${pkgs[@]}"; do
    if [[ "$pkg" != *t64 ]] && has_candidate "${pkg}t64"; then
      continue
    fi
    if has_candidate "$pkg"; then
      available+=("$pkg")
    fi
  done
  if [[ ${#available[@]} -eq 0 ]]; then
    echo "Could not find the libraries the headless browser needs." >&2
    return 1
  fi
  run_root apt-get install -y --no-install-recommends "${available[@]}"
}

need_chrome=0
need_pdf=0
if ! real_browser; then
  need_chrome=1
fi
if ! have_pdftoppm; then
  need_pdf=1
fi
if [[ "$need_chrome" -eq 0 && "$need_pdf" -eq 0 ]]; then
  exit 0
fi

os="$(uname -s)"
if [[ "$os" == "Linux" ]] && command -v apt-get >/dev/null 2>&1; then
  if [[ "$need_chrome" -eq 1 ]]; then
    echo "A headless browser that this service can start is missing."
  fi
  if [[ "$need_pdf" -eq 1 ]]; then
    echo "poppler-utils is missing. Scanned PDFs need it."
  fi
  export DEBIAN_FRONTEND=noninteractive
  if ! run_root apt-get update; then
    echo "Could not update the package list for the headless browser." >&2
    exit 1
  fi
  if [[ "$need_pdf" -eq 1 ]]; then
    if ! run_root apt-get install -y poppler-utils; then
      echo "Could not install poppler-utils. On Ubuntu, run: sudo apt-get install -y poppler-utils" >&2
      exit 1
    fi
  fi
  if [[ "$need_chrome" -eq 1 ]]; then
    if ! install_browser_libs; then
      echo "Could not install the libraries the headless browser needs." >&2
      exit 1
    fi
    if ! download_headless; then
      exit 1
    fi
  fi
  if [[ "$need_chrome" -eq 1 ]] && ! real_browser; then
    echo "The headless browser is still missing." >&2
    exit 1
  fi
  if [[ "$need_pdf" -eq 1 ]] && ! have_pdftoppm; then
    echo "poppler-utils is still missing. On Ubuntu, run: sudo apt-get install -y poppler-utils" >&2
    exit 1
  fi
  echo "Installed the packages screenshots, the headless browser, and scanned PDFs need."
  exit 10
fi

if [[ "$os" == "Linux" ]] && command -v dnf >/dev/null 2>&1; then
  if [[ "$need_chrome" -eq 1 ]]; then
    echo "Chromium is missing. Screenshots and the headless browser need it."
  fi
  if [[ "$need_pdf" -eq 1 ]]; then
    echo "poppler-utils is missing. Scanned PDFs need it."
  fi
  packages=()
  [[ "$need_chrome" -eq 1 ]] && packages+=(chromium liberation-fonts)
  [[ "$need_pdf" -eq 1 ]] && packages+=(poppler-utils)
  if ! run_root dnf install -y "${packages[@]}"; then
    echo "Could not install Chromium or poppler-utils with dnf." >&2
    exit 1
  fi
  if [[ "$need_chrome" -eq 1 ]] && ! real_browser; then
    download_headless || exit 1
  fi
  exit 10
fi

if [[ "$os" == "Linux" ]] && command -v pacman >/dev/null 2>&1; then
  if [[ "$need_chrome" -eq 1 ]]; then
    echo "Chromium is missing. Screenshots and the headless browser need it."
  fi
  if [[ "$need_pdf" -eq 1 ]]; then
    echo "poppler-utils is missing. Scanned PDFs need it."
  fi
  packages=()
  [[ "$need_chrome" -eq 1 ]] && packages+=(chromium ttf-liberation)
  [[ "$need_pdf" -eq 1 ]] && packages+=(poppler)
  if ! run_root pacman -Sy --needed --noconfirm "${packages[@]}"; then
    echo "Could not install Chromium or poppler with pacman." >&2
    exit 1
  fi
  if [[ "$need_chrome" -eq 1 ]] && ! real_browser; then
    download_headless || exit 1
  fi
  exit 10
fi

if [[ "$need_chrome" -eq 1 && "$os" == "Linux" ]]; then
  download_headless || exit 1
  if [[ "$need_pdf" -eq 1 ]]; then
    echo "poppler-utils is missing. Scanned PDFs need it." >&2
  fi
  exit 10
fi
if [[ "$need_chrome" -eq 1 ]]; then
  echo "Chromium is missing. Screenshots and the headless browser need it." >&2
fi
if [[ "$need_pdf" -eq 1 ]]; then
  echo "poppler-utils is missing. Scanned PDFs need it." >&2
fi
if [[ "$os" == "Darwin" ]]; then
  echo "Linux installs these with the agent. On this Mac, install Google Chrome or Chromium. A scanned PDF is read with Quick Look until poppler is installed." >&2
  exit 0
fi
echo "Install Chromium and poppler-utils with this system's package manager, then run this again." >&2
exit 1
