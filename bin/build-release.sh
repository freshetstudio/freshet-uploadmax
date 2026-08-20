#!/bin/bash
#
# Build the distributable ZIP(s) for this plugin.
#
#   bash bin/build-release.sh
#     → dist/<slug>-<version>-wporg.zip   the wordpress.org directory build
#     → dist/<slug>-<version>.zip         only where the plugin also sells direct
#
# THIS FILE IS IDENTICAL IN EVERY FRESHET PLUGIN REPO. Everything that differs
# per plugin — slug, version constant, which build steps run, extra excludes,
# which files the directory build strips — lives in bin/release.conf. Change the
# config, never this script; a hand-edit here is drift.
#
# Ships: the plugin's runtime files. Excludes: dev tooling, tests, repo docs.
# Optionally tags HEAD v{version} locally when the tree is clean; pushing is
# always manual.

set -e

cd "$(dirname "$0")/.."

CONF="bin/release.conf"
if [ ! -f "$CONF" ]; then
  echo "✗ Missing $CONF — the per-plugin half of the release toolchain." >&2
  exit 1
fi

# Defaults, so a config only has to state what it changes.
SLUG=""
VERSION_FILE=""
VERSION_CONST=""
BUILD_NPM=0
BUILD_COMPOSER=0
SALES_ZIP=0
TAG_RELEASE=0
EXTRA_EXCLUDES=()
WPORG_STRIP=()

# shellcheck source=/dev/null
. "$CONF"

for key in SLUG VERSION_FILE VERSION_CONST; do
  if [ -z "$(eval "echo \"\$$key\"")" ]; then
    echo "✗ $CONF does not set $key" >&2
    exit 1
  fi
done

# Paths no Freshet plugin ever ships. Per-plugin additions go in the config's
# EXTRA_EXCLUDES; this baseline is shared and stays shared.
#
# Every dot-entry goes in one pattern rather than a named list: no plugin here
# ships a dotfile or dot-directory, and an enumeration would have to spell out
# the local tooling directories this archive exists to keep out.
EXCLUDES=(
  '.*'
  'node_modules'
  'vendor/bin'
  'dist'
  'tests'
  'bin'
  'phpcs.xml.dist'
  'phpunit.xml.dist'
  'package-lock.json'
  'webpack.config.js'
  'CLAUDE.md'
  'CREDENTIALS.md'
  'DEPLOY.md'
  'DEPLOY.sh'
  'ROADMAP.md'
  'TODO.md'
  'README.md'
)

# Build output counts as published — the exclude list above and .gitignore are
# separate lists, and only this one decides what a user receives. So assert it
# rather than trust it: repo-local notes, deploy scripts, credential files and
# dev directories never belong in a distributed archive, whatever the exclude
# list says. Verification is a listing of the built archive, never a reading of
# the recipe.
INTERNAL_FILES='(^|/)(CLAUDE\.md|TODO\.md|ROADMAP\.md|DEPLOY\.(md|sh)|CREDENTIALS\.md|\.env(\..+)?)$'
INTERNAL_PATHS='(^|/)(\.[^/]+|node_modules|tests)/'

assert_shippable() {
  local zip="$1" listing hit
  listing=$(unzip -Z1 "$zip")

  hit=$(echo "$listing" | grep -E "$INTERNAL_FILES" || true)
  if [ -n "$hit" ]; then
    echo "Release blocked: internal file inside $zip:" >&2
    echo "$hit" | sed 's/^/  /' >&2
    exit 1
  fi

  hit=$(echo "$listing" | grep -E "$INTERNAL_PATHS" || true)
  if [ -n "$hit" ]; then
    echo "Release blocked: internal directory inside $zip:" >&2
    echo "$hit" | sed 's/^/  /' >&2
    exit 1
  fi

  hit=$(echo "$listing" | grep -iE '\.md$' || true)
  if [ -n "$hit" ]; then
    echo "Release blocked: repo docs inside $zip — readme.txt is the only doc that ships:" >&2
    echo "$hit" | sed 's/^/  /' >&2
    exit 1
  fi
}

# The version a user installs has to be the version the listing advertises, so
# read all three and refuse to build when they disagree.
resolve_version() {
  local from_const from_header from_readme
  from_const=$(grep -m1 "$VERSION_CONST" "$VERSION_FILE" | sed "s/.*'\([0-9.]*\)'.*/\1/")
  from_header=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$VERSION_FILE" \
    | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
  from_readme=$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/.*:[[:space:]]*//' | tr -d '[:space:]')

  if [ -z "$from_const" ]; then
    echo "✗ Could not read $VERSION_CONST from $VERSION_FILE" >&2
    exit 1
  fi
  if [ "$from_const" != "$from_header" ] || [ "$from_const" != "$from_readme" ]; then
    echo "✗ Version disagreement — $VERSION_CONST=$from_const, header=$from_header, Stable tag=$from_readme" >&2
    exit 1
  fi

  VERSION="$from_const"
}

# A shipped ZIP has to be reproducible from a commit, so a release build can
# leave a tag behind. Local only — pushing stays a deliberate, separate step.
tag_release() {
  local tag="v${VERSION}" existing

  if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Not a git checkout — skipping tag $tag." >&2
    return
  fi

  existing=$(git rev-parse -q --verify "refs/tags/$tag^{commit}" || true)
  if [ -n "$existing" ]; then
    if [ "$existing" = "$(git rev-parse HEAD)" ]; then
      echo "Tag $tag already on HEAD."
      return
    fi
    echo "Release built, but $tag already points at another commit — bump the version." >&2
    exit 1
  fi

  if [ -n "$(git status --porcelain)" ]; then
    echo "Uncommitted changes — not tagging. Commit, then: git tag -a $tag -m \"${SLUG} ${VERSION}\"" >&2
    return
  fi

  git tag -a "$tag" -m "${SLUG} ${VERSION}"
  echo "Tagged $tag — push it with: git push origin $tag"
}

resolve_version

STAGE="dist/${SLUG}"
ZIP="dist/${SLUG}-${VERSION}.zip"
WPORG_ZIP="dist/${SLUG}-${VERSION}-wporg.zip"

echo "Building ${SLUG} ${VERSION}..."

if [ "$BUILD_NPM" = "1" ]; then
  npm run build --silent
fi
if [ "$BUILD_COMPOSER" = "1" ]; then
  composer install --no-dev --quiet --optimize-autoloader
fi

rm -rf dist
mkdir -p "$STAGE"

RSYNC_ARGS=()
for pattern in "${EXCLUDES[@]}" "${EXTRA_EXCLUDES[@]}"; do
  RSYNC_ARGS+=( --exclude="$pattern" )
done

rsync -a "${RSYNC_ARGS[@]}" ./ "$STAGE/"

# readme.txt may tell a reviewer to run `npm install && npm run build` on the
# shipped package, so the staged package.json must carry no lifecycle script
# that reaches for a file the archive does not contain.
if [ -f "$STAGE/package.json" ]; then
  node -e "const fs=require('fs'),f='$STAGE/package.json',p=JSON.parse(fs.readFileSync(f));if(p.scripts){delete p.scripts.prepare}fs.writeFileSync(f,JSON.stringify(p,null,4)+'\n')"
fi

if [ "$SALES_ZIP" = "1" ]; then
  (cd dist && zip -qr "$(basename "$ZIP")" "$SLUG")
  assert_shippable "$ZIP"
fi

# The wp.org variant: strip anything the directory guidelines exclude (license
# checks, update injection, upsell surfaces) and regenerate the optimized
# classmap so no entry points at a stripped file.
if [ ${#WPORG_STRIP[@]} -gt 0 ]; then
  for stripped in "${WPORG_STRIP[@]}"; do
    rm "$STAGE/$stripped"
  done
  if [ "$BUILD_COMPOSER" = "1" ]; then
    (cd "$STAGE" && composer dump-autoload --no-dev --optimize --quiet)
  fi
fi

(cd dist && zip -qr "$(basename "$WPORG_ZIP")" "$SLUG")
assert_shippable "$WPORG_ZIP"

rm -rf "$STAGE"

# Restore dev dependencies for local work.
if [ "$BUILD_COMPOSER" = "1" ]; then
  composer install --quiet
fi

if [ "$SALES_ZIP" = "1" ]; then
  echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
fi
echo "Built $WPORG_ZIP ($(du -h "$WPORG_ZIP" | cut -f1))"

if [ "$TAG_RELEASE" = "1" ]; then
  tag_release
fi
