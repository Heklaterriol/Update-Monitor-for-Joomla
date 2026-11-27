#!/usr/bin/env bash
# Builds dist/pkg_updatemonitor.zip and refreshes version, download URL and sha256 in updatemonitor_update.xml.
# Works on Linux and macOS (needs bash, zip and sha256sum or shasum).
set -euo pipefail
cd "$(dirname "$0")/.."

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  else shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

# zip without extra file attributes and without macOS metadata files
mkzip() { zip -rqX "$1" . -x "*.DS_Store" -x "__MACOSX/*" -x "._*"; }

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg/pkg_updatemonitor.xml | head -1)
ROOT=$(pwd)
rm -rf build dist && mkdir -p build/pkg/packages dist

(cd com && mkzip "$ROOT/build/pkg/packages/com_updatemonitor.zip")
(cd plg && mkzip "$ROOT/build/pkg/packages/plg_task_updatemonitor.zip")
cp pkg/pkg_updatemonitor.xml pkg/script.php build/pkg/
(cd build/pkg && mkzip "$ROOT/dist/pkg_updatemonitor.zip")

SHA=$(sha256 dist/pkg_updatemonitor.zip)

# portable in-place edit (GNU and BSD sed differ on -i)
TMP=$(mktemp)
sed \
  -e "s#<sha256>.*</sha256>#<sha256>${SHA}</sha256>#" \
  -e "s#<version>[^<]*</version>#<version>${VERSION}</version>#" \
  -e "s#/releases/download/v[^/]*/#/releases/download/v${VERSION}/#" \
  updatemonitor_update.xml > "$TMP"
cat "$TMP" > updatemonitor_update.xml
rm -f "$TMP"

echo "Built dist/pkg_updatemonitor.zip (version ${VERSION})"
echo "sha256: ${SHA}"
echo "Upload this exact file as release asset 'pkg_updatemonitor.zip' of tag v${VERSION}, then commit updatemonitor_update.xml."

if grep -q "YOUR_GITHUB_USER" pkg/pkg_updatemonitor.xml updatemonitor_update.xml; then
  echo "WARNING: replace YOUR_GITHUB_USER/YOUR_REPO in pkg/pkg_updatemonitor.xml and updatemonitor_update.xml before releasing." >&2
fi
