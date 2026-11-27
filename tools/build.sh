#!/usr/bin/env bash
# Builds dist/pkg_updatemonitor.zip and refreshes version, download URL and sha256 in updatemonitor_update.xml.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg/pkg_updatemonitor.xml | head -1)
rm -rf build dist && mkdir -p build/pkg/packages dist

(cd com && zip -rq ../build/pkg/packages/com_updatemonitor.zip .)
(cd plg && zip -rq ../build/pkg/packages/plg_task_updatemonitor.zip .)
cp pkg/pkg_updatemonitor.xml pkg/script.php build/pkg/
(cd build/pkg && zip -rq ../../dist/pkg_updatemonitor.zip .)

SHA=$(sha256sum dist/pkg_updatemonitor.zip | cut -d' ' -f1)
sed -i \
  -e "s#<sha256>.*</sha256>#<sha256>${SHA}</sha256>#" \
  -e "s#<version>[^<]*</version>#<version>${VERSION}</version>#" \
  -e "s#/releases/download/v[^/]*/#/releases/download/v${VERSION}/#" \
  updatemonitor_update.xml

echo "Built dist/pkg_updatemonitor.zip (version ${VERSION})"
echo "sha256: ${SHA}"
echo "Upload this exact file as release asset 'pkg_updatemonitor.zip' of tag v${VERSION}, then commit updatemonitor_update.xml."

if grep -rq "YOUR_GITHUB_USER" pkg/pkg_updatemonitor.xml updatemonitor_update.xml; then
  echo "WARNING: replace YOUR_GITHUB_USER/YOUR_REPO in pkg/pkg_updatemonitor.xml and updatemonitor_update.xml before releasing." >&2
fi
