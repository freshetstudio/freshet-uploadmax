#!/bin/bash
#
# Rasterise the wp.org page assets from their SVG sources in .wordpress-org/.
#
#   bash bin/render-assets.sh
#
# THIS FILE IS IDENTICAL IN EVERY FRESHET PLUGIN REPO. What to render is data
# and lives in ASSET_RENDERS in bin/release.conf, one entry per output:
#
#     <svg> <render-size> <out.png> <crop-height>
#
# wp.org banners/screenshots must be PNG (SVG is only accepted for the icon,
# which therefore ships as-is). macOS has no ImageMagick/PIL, so we rasterise
# with qlmanage (WebKit) — which pads its output to a square and top-left-aligns
# the content — then top-crop to the exact target size with bin/pngcrop.js.
#
# SVG sources whose content is near-square are authored on a SQUARE canvas so
# qlmanage renders them 1:1 (it "covers" non-square SVGs, clipping them).

set -euo pipefail
cd "$(dirname "$0")/.."

CONF="bin/release.conf"
if [ ! -f "$CONF" ]; then
  echo "✗ Missing $CONF — the per-plugin half of the release toolchain." >&2
  exit 1
fi

ASSET_RENDERS=()

# shellcheck source=/dev/null
. "$CONF"

DIR=".wordpress-org"
CROP="node bin/pngcrop.js"

if [ ${#ASSET_RENDERS[@]} -eq 0 ]; then
  echo "No ASSET_RENDERS configured in $CONF — nothing to rasterise."
  exit 0
fi

if [ ! -d "$DIR" ]; then
  echo "✗ Missing $DIR/ — the SVG sources for the wp.org page assets live there." >&2
  exit 1
fi

render() { # <svg> <render-size> <out.png> <crop-height>
  qlmanage -t -s "$2" -o "$DIR" "$DIR/$1" >/dev/null 2>&1
  $CROP "$DIR/${1%.svg}.svg.png" "$DIR/$3" "$4"
  rm -f "$DIR/${1%.svg}.svg.png"
}

for spec in "${ASSET_RENDERS[@]}"; do
  # shellcheck disable=SC2086
  render $spec
done

echo "✓ Assets rendered in $DIR/ (icon.svg ships as-is)"
