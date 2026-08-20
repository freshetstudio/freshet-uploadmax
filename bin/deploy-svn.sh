#!/bin/bash
#
# Stage a release into this plugin's wordpress.org SVN working copy.
#
#   bash bin/deploy-svn.sh [--skip-build]
#
# THIS FILE IS IDENTICAL IN EVERY FRESHET PLUGIN REPO. The slug, the version
# constant and the SVN account live in bin/release.conf.
#
# What it does (all local, NON-destructive to the live directory):
#   1. Builds the wp.org ZIP (bin/build-release.sh) — the directory build,
#      never a direct-sales build.
#   2. Checks out (or updates) the SVN repo into .wporg-svn/ (gitignored).
#   3. Syncs the unzipped build into trunk/ (with --delete) and stages adds/dels.
#   4. Syncs wp.org page assets (.wordpress-org/ → assets/).
#   5. Copies trunk/ to tags/<version>/ (skipped if the tag already exists).
#   6. STOPS. Prints `svn status` and the exact `svn ci` command to run.
#
# It deliberately does NOT commit. The commit needs your wp.org SVN password
# (Account & Security on wordpress.org — not your login, not your email), and a
# push to the directory is public and hard to reverse. Run the printed command
# yourself once you've eyeballed the staged tree.

set -euo pipefail

cd "$(dirname "$0")/.."

CONF="bin/release.conf"
if [ ! -f "$CONF" ]; then
  echo "✗ Missing $CONF — the per-plugin half of the release toolchain." >&2
  exit 1
fi

SLUG=""
VERSION_FILE=""
VERSION_CONST=""
SVN_USER=""

# shellcheck source=/dev/null
. "$CONF"

if [ -z "$SLUG" ] || [ -z "$VERSION_FILE" ] || [ -z "$VERSION_CONST" ]; then
  echo "✗ $CONF must set SLUG, VERSION_FILE and VERSION_CONST" >&2
  exit 1
fi

SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_DIR=".wporg-svn"
ASSETS_SRC=".wordpress-org"

VERSION=$(grep -m1 "$VERSION_CONST" "$VERSION_FILE" | sed "s/.*'\([0-9.]*\)'.*/\1/")

if [ -z "$VERSION" ]; then
  echo "✗ Could not read ${VERSION_CONST} from ${VERSION_FILE}" >&2
  exit 1
fi

if ! command -v svn >/dev/null 2>&1; then
  echo "✗ svn is not installed. Install it first:  brew install svn" >&2
  exit 1
fi

echo "▶ Deploying ${SLUG} ${VERSION} to SVN staging"

# 1. Build the wp.org ZIP unless told to reuse the existing one.
if [ "${1:-}" != "--skip-build" ]; then
  bash bin/build-release.sh
fi

WPORG_ZIP="dist/${SLUG}-${VERSION}-wporg.zip"
if [ ! -f "$WPORG_ZIP" ]; then
  echo "✗ Missing $WPORG_ZIP (run without --skip-build to build it)" >&2
  exit 1
fi

# 2. Check out or update the SVN working copy.
if [ ! -d "$SVN_DIR/.svn" ]; then
  echo "▶ Checking out $SVN_URL"
  svn checkout "$SVN_URL" "$SVN_DIR"
else
  echo "▶ Updating existing working copy"
  svn update "$SVN_DIR"
fi

mkdir -p "$SVN_DIR/trunk" "$SVN_DIR/assets"

# 3. Unzip the build and sync into trunk/.
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
unzip -q "$WPORG_ZIP" -d "$STAGE"

echo "▶ Syncing build → trunk/"
rsync -a --delete --exclude='.svn' "$STAGE/${SLUG}/" "$SVN_DIR/trunk/"

# 4. Sync wp.org page assets (only the filenames wp.org recognises).
if [ -d "$ASSETS_SRC" ]; then
  echo "▶ Syncing assets → assets/"
  rsync -a \
    --include='icon.svg' \
    --include='icon-*.png' \
    --include='banner-*.png' \
    --include='banner-*.jpg' \
    --include='screenshot-*.png' \
    --include='screenshot-*.jpg' \
    --exclude='*' \
    "$ASSETS_SRC/" "$SVN_DIR/assets/"
else
  echo "▶ No ${ASSETS_SRC}/ yet — skipping page assets"
fi

# 5. Stage adds and deletes across the whole working copy.
cd "$SVN_DIR"
# Add anything untracked.
svn add --force trunk assets >/dev/null 2>&1 || true
# Remove anything that vanished from the source (status '!').
svn status | awk '/^!/ {print $2}' | while read -r f; do svn rm --force "$f" >/dev/null 2>&1 || true; done

# 6. Tag the release (skip if it already exists).
if svn info "tags/${VERSION}" >/dev/null 2>&1 || [ -d "tags/${VERSION}" ]; then
  echo "▶ Tag tags/${VERSION} already exists — leaving it untouched"
else
  echo "▶ Copying trunk → tags/${VERSION}"
  svn cp trunk "tags/${VERSION}"
fi

echo
echo "──────────────────────────────────────────────────────────────"
echo " Staged. Nothing has been committed to wp.org yet."
echo "──────────────────────────────────────────────────────────────"
svn status
echo
echo " Review the tree above, then commit from ${SVN_DIR}/:"
echo
echo "   cd ${SVN_DIR}"
echo "   svn ci -m \"Release ${VERSION}\" --username ${SVN_USER}"
echo
echo " (SVN will prompt for your wp.org SVN password the first time.)"
