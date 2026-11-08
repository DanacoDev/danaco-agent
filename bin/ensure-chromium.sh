#!/usr/bin/env bash
# Install Chromium for screenshots and the headless browser.
# Exit 10 when it was installed, 0 when it was already present.
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

if have_chromium; then
  exit 0
fi

os="$(uname -s)"
if [[ "$os" == "Linux" ]] && command -v apt-get >/dev/null 2>&1; then
  echo "Chromium is missing. Screenshots and the headless browser need it."
  export DEBIAN_FRONTEND=noninteractive
  if ! run_root bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && { apt-get install -y chromium fonts-liberation || apt-get install -y chromium-browser fonts-liberation; }'; then
    echo "Could not install Chromium. On Ubuntu, run: sudo apt-get install -y chromium-browser" >&2
    exit 1
  fi
  if ! have_chromium; then
    echo "Chromium is still missing. On Ubuntu, run: sudo apt-get install -y chromium-browser" >&2
    exit 1
  fi
  echo "Installed Chromium for screenshots and the headless browser."
  exit 10
fi

echo "Chromium is missing. Screenshots and the headless browser need it." >&2
if [[ "$os" == "Darwin" ]]; then
  echo "Linux installs it with the agent. On this Mac, install Google Chrome or Chromium." >&2
  exit 0
fi
echo "Install chromium, then run this again." >&2
exit 1
