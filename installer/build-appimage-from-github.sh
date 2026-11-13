#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${DANACO_REPOSITORY:-DanacoDev/danaco-agent}"
REPOSITORY="${REPOSITORY%/}"
REPOSITORY="${REPOSITORY%.git}"
BRANCH="${DANACO_BRANCH:-main}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/danaco-appimage-build.XXXXXX")"
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

echo "Fetching $REPOSITORY..."
gh repo clone "$REPOSITORY" "$REPO_DIR"
git -C "$REPO_DIR" fetch --depth 1 origin "$BRANCH"
git -C "$REPO_DIR" checkout --detach FETCH_HEAD

echo "Building the AppImage..."
sh "$REPO_DIR/installer/build-appimage.sh"

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
