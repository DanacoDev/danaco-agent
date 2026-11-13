#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${DANACO_REPOSITORY:-DanacoDev/danaco-agent}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/danaco-appimage-build.XXXXXX")"
VENV_DIR="$WORK_DIR/venv"
REPO_DIR="$WORK_DIR/repository"
trap 'rm -rf "$WORK_DIR"' EXIT

if [[ "$(uname -s)" != "Linux" || "$(uname -m)" != "x86_64" ]]; then
  echo "This builder supports Linux x86_64 only." >&2
  exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "GitHub CLI (gh) is required." >&2
  exit 1
fi
if ! gh auth status >/dev/null 2>&1; then
  echo "Sign in to GitHub first with: gh auth login" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "Python 3 is required." >&2
  exit 1
fi

if ! python3 -m venv "$VENV_DIR" >/dev/null 2>&1; then
  echo "Installing the Python virtual-environment package..."
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "Install python3-venv with your distribution's package manager, then run this again." >&2
    exit 1
  fi
  sudo apt-get update
  sudo apt-get install -y python3-venv
  python3 -m venv "$VENV_DIR"
fi

echo "Fetching $REPOSITORY..."
gh repo clone "$REPOSITORY" "$REPO_DIR" -- --depth=1

echo "Installing the AppImage builder..."
"$VENV_DIR/bin/pip" install --quiet appimage-builder

echo "Building the AppImage..."
(
  cd "$REPO_DIR"
  PATH="$VENV_DIR/bin:$PATH" sh installer/build-appimage.sh
)

VERSION="$(tr -d '[:space:]' < "$REPO_DIR/VERSION")"
SOURCE="$REPO_DIR/dist/danaco-agent-setup-${VERSION}-x86_64.AppImage"
DEST="$SCRIPT_DIR/danaco-agent-setup-${VERSION}-x86_64.AppImage"

if [[ ! -f "$SOURCE" ]]; then
  echo "The AppImage build completed without producing: $SOURCE" >&2
  exit 1
fi

cp "$SOURCE" "$DEST"
chmod +x "$DEST"
echo "Created: $DEST"
