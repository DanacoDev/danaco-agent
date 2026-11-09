#!/usr/bin/env bash
# Install Chromium for screenshots and the headless browser, and poppler for scanned PDFs.
# Exit 10 when something was installed, 0 when both were already present.
set -euo pipefail

have_chromium() {
  local name path
  for name in chromium chromium-browser google-chrome-stable google-chrome; do
    if command -v "$name" >/dev/null 2>&1; then
      return 0
    fi
  done
  for path in \
    /usr/bin/chromium \
    /usr/bin/chromium-browser \
    /usr/bin/google-chrome-stable \
    /usr/bin/google-chrome \
    /snap/bin/chromium
  do
    if [[ -x "$path" ]]; then
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

need_chrome=0
need_pdf=0
if ! have_chromium; then
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
    echo "Chromium is missing. Screenshots and the headless browser need it."
  fi
  if [[ "$need_pdf" -eq 1 ]]; then
    echo "poppler-utils is missing. Scanned PDFs need it."
  fi
  export DEBIAN_FRONTEND=noninteractive
  if ! run_root bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && { if [ "$1" = 1 ]; then apt-get install -y chromium fonts-liberation || apt-get install -y chromium-browser fonts-liberation; fi; if [ "$2" = 1 ]; then apt-get install -y poppler-utils; fi; }' bash "$need_chrome" "$need_pdf"; then
    echo "Could not install Chromium or poppler-utils. On Ubuntu, run: sudo apt-get install -y chromium-browser poppler-utils" >&2
    exit 1
  fi
  if [[ "$need_chrome" -eq 1 ]] && ! have_chromium; then
    echo "Chromium is still missing. On Ubuntu, run: sudo apt-get install -y chromium-browser" >&2
    exit 1
  fi
  if [[ "$need_pdf" -eq 1 ]] && ! have_pdftoppm; then
    echo "poppler-utils is still missing. On Ubuntu, run: sudo apt-get install -y poppler-utils" >&2
    exit 1
  fi
  echo "Installed the packages screenshots, the headless browser, and scanned PDFs need."
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
echo "Install chromium and poppler-utils, then run this again." >&2
exit 1
