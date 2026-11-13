#!/bin/sh
# Build a portable x86_64 AppImage for the graphical setup window.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
OUT="$ROOT/dist/danaco-agent-setup-${VERSION}-x86_64.AppImage"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/danaco-appimage.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

if [ "$(uname -s)" != "Linux" ]; then
  echo "AppImage builds must run on Linux (or in the Linux CI workflow)." >&2
  exit 1
fi
if ! command -v appimage-builder >/dev/null 2>&1; then
  echo "Install appimage-builder before running this script." >&2
  exit 1
fi

cp "$ROOT/installer/appimage/appimage-builder.yml" "$WORK/"
sed "s/version: 1.0.45/version: $VERSION/" \
  "$ROOT/installer/appimage/appimage-builder.yml" > "$WORK/appimage-builder.yml"
mkdir -p "$WORK/AppDir/usr/share/danaco-agent-setup"
cp "$ROOT/installer/appimage/AppRun" "$WORK/AppDir/AppRun"
cp "$ROOT/installer/appimage/danaco-agent-setup.desktop" \
  "$WORK/AppDir/danaco-agent-setup.desktop"
cp "$ROOT/installer/danaco-setup.py" \
  "$WORK/AppDir/usr/share/danaco-agent-setup/danaco-setup.py"
cp "$ROOT/public/assets/logo.png" "$WORK/AppDir/danaco-agent-setup.png"
chmod +x "$WORK/AppDir/AppRun"

(cd "$WORK" && appimage-builder --recipe appimage-builder.yml)
mkdir -p "$ROOT/dist"
mv "$WORK"/Danaco_Agent_Setup*.AppImage "$OUT"
printf '%s\n' "$OUT"
