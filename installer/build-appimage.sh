#!/bin/sh
# Build a portable x86_64 AppImage for the graphical setup window.
# Uses this computer's apt packages. Build it on Ubuntu 22.04 or 24.04 so the
# result can run on newer distributions.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
OUT="$ROOT/dist/danaco-agent-setup-${VERSION}-x86_64.AppImage"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/danaco-appimage.XXXXXX")"
APPDIR="$WORK/AppDir"
SEEN="$WORK/seen"
trap 'rm -rf "$WORK"' EXIT

if [ "$(uname -s)" != "Linux" ] || [ "$(uname -m)" != "x86_64" ]; then
  echo "This builder supports Linux x86_64 only." >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This builder needs apt-get. Run it on Ubuntu, Debian, Mint, or Kali." >&2
  exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y curl ca-certificates
fi

if ! python3 -c 'import gi' >/dev/null 2>&1 || [ ! -f /usr/lib/x86_64-linux-gnu/girepository-1.0/Gtk-4.0.typelib ]; then
  echo "Installing the GTK setup libraries..."
  sudo apt-get update
  sudo apt-get install -y python3 python3-gi gir1.2-gtk-4.0
fi

mkdir -p "$APPDIR/usr/bin" "$APPDIR/usr/lib" "$APPDIR/usr/lib/girepository-1.0" \
  "$APPDIR/usr/lib/python3/dist-packages" "$APPDIR/usr/share/danaco-agent-setup"
: > "$SEEN"

skip_lib() {
  case "$(basename "$1")" in
    ld-linux*.so*|libc.so.*|libm.so.*|libdl.so.*|libpthread.so.*|librt.so.*|libresolv.so.*|libutil.so.*)
      return 0
      ;;
  esac
  return 1
}

bundle_deps() {
  ldd "$1" 2>/dev/null | awk '/=> \// { print $3 }' | while read -r dep; do
    [ -f "$dep" ] || continue
    name="$(basename "$dep")"
    real="$(readlink -f "$dep")"
    if skip_lib "$real"; then
      continue
    fi
    if grep -Fxq "$name" "$SEEN"; then
      continue
    fi
    printf '%s\n' "$name" >> "$SEEN"
    cp -a "$real" "$APPDIR/usr/lib/$name"
    bundle_deps "$APPDIR/usr/lib/$name"
  done
}

py_real="$(readlink -f "$(command -v python3)")"
py_ver="$(python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])')"
cp -a "$py_real" "$APPDIR/usr/bin/python3"
chmod +x "$APPDIR/usr/bin/python3"
bundle_deps "$APPDIR/usr/bin/python3"
cp -a "/usr/lib/python${py_ver}" "$APPDIR/usr/lib/"
cp -a /usr/lib/python3/dist-packages/gi "$APPDIR/usr/lib/python3/dist-packages/"
for extra in /usr/lib/python3/dist-packages/_gi*.so \
  /usr/lib/python3/dist-packages/gi_cairo.py \
  /usr/lib/python3/dist-packages/_gi_cairo*; do
  if [ -e "$extra" ]; then
    cp -a "$extra" "$APPDIR/usr/lib/python3/dist-packages/"
  fi
done
if [ -d /usr/lib/python3/dist-packages/pygtkcompat ]; then
  cp -a /usr/lib/python3/dist-packages/pygtkcompat "$APPDIR/usr/lib/python3/dist-packages/"
fi
if [ -d /usr/lib/python3/dist-packages/cairo ]; then
  cp -a /usr/lib/python3/dist-packages/cairo "$APPDIR/usr/lib/python3/dist-packages/"
fi
cp -a /usr/lib/x86_64-linux-gnu/girepository-1.0/. "$APPDIR/usr/lib/girepository-1.0/"
if [ -d /usr/lib/x86_64-linux-gnu/gdk-pixbuf-2.0 ]; then
  mkdir -p "$APPDIR/usr/lib/gdk-pixbuf-2.0"
  cp -a /usr/lib/x86_64-linux-gnu/gdk-pixbuf-2.0/. "$APPDIR/usr/lib/gdk-pixbuf-2.0/"
fi
if [ -f /usr/share/glib-2.0/schemas/gschemas.compiled ]; then
  mkdir -p "$APPDIR/usr/share/glib-2.0/schemas"
  cp -a /usr/share/glib-2.0/schemas/gschemas.compiled "$APPDIR/usr/share/glib-2.0/schemas/"
fi

find "$APPDIR/usr/lib/python${py_ver}/lib-dynload" "$APPDIR/usr/lib/python3/dist-packages" \
  "$APPDIR/usr/lib/gdk-pixbuf-2.0" \
  -name '*.so' -type f 2>/dev/null | while read -r so; do
  bundle_deps "$so"
done
if [ -e /usr/lib/x86_64-linux-gnu/libgtk-4.so.1 ]; then
  if ! grep -Fxq "libgtk-4.so.1" "$SEEN"; then
    printf '%s\n' "libgtk-4.so.1" >> "$SEEN"
    cp -a "$(readlink -f /usr/lib/x86_64-linux-gnu/libgtk-4.so.1)" "$APPDIR/usr/lib/libgtk-4.so.1"
  fi
  bundle_deps "$APPDIR/usr/lib/libgtk-4.so.1"
fi

cp "$ROOT/installer/appimage/AppRun" "$APPDIR/AppRun"
cp "$ROOT/installer/appimage/danaco-agent-setup" "$APPDIR/usr/bin/danaco-agent-setup"
cp "$ROOT/installer/appimage/danaco-agent-setup.desktop" "$APPDIR/danaco-agent-setup.desktop"
cp "$ROOT/installer/danaco-setup.py" "$APPDIR/usr/share/danaco-agent-setup/danaco-setup.py"
cp "$ROOT/public/assets/logo.png" "$APPDIR/danaco-agent-setup.png"
chmod +x "$APPDIR/AppRun" "$APPDIR/usr/bin/danaco-agent-setup"

if ! ldconfig -p 2>/dev/null | grep -q 'libfuse\.so\.2'; then
  echo "Installing the library a double-clicked AppImage needs..."
  sudo apt-get update
  sudo apt-get install -y libfuse2 || sudo apt-get install -y libfuse2t64
fi

echo "Downloading the AppImage packager..."
curl -fsSL -o "$WORK/appimagetool.AppImage" \
  "https://github.com/AppImage/appimagetool/releases/download/continuous/appimagetool-x86_64.AppImage"
curl -fsSL -o "$WORK/runtime-x86_64" \
  "https://github.com/AppImage/type2-runtime/releases/download/continuous/runtime-x86_64"
chmod +x "$WORK/appimagetool.AppImage" "$WORK/runtime-x86_64"
(cd "$WORK" && APPIMAGE_EXTRACT_AND_RUN=1 "$WORK/appimagetool.AppImage" --appimage-extract >/dev/null)
mkdir -p "$ROOT/dist"
ARCH=x86_64 "$WORK/squashfs-root/AppRun" --runtime-file "$WORK/runtime-x86_64" "$APPDIR" "$OUT"
chmod +x "$OUT"
cp "$OUT" "$ROOT/installer/danaco-agent-setup-${VERSION}-x86_64.AppImage"
chmod +x "$ROOT/installer/danaco-agent-setup-${VERSION}-x86_64.AppImage"
printf '%s\n' "$ROOT/installer/danaco-agent-setup-${VERSION}-x86_64.AppImage"
