#!/usr/bin/env bash
# Cut a GitHub Release for Forma. Installs around the world only pull releases.
# Usage (from repo root, on main, version.php already bumped):
#   ./tools/release.sh
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

if ! command -v gh >/dev/null; then
  echo "gh CLI is required (https://cli.github.com/)" >&2
  exit 1
fi
if ! command -v php >/dev/null; then
  echo "php is required to read version.php" >&2
  exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$branch" != "main" ]]; then
  echo "Refusing: not on main (on $branch). Merge first." >&2
  exit 1
fi

dirty="$(git status --porcelain)"
if [[ -n "$dirty" ]]; then
  echo "Refusing: working tree is dirty. Commit or stash first:" >&2
  echo "$dirty" >&2
  exit 1
fi

version="$(php -r "require '$root/version.php'; echo FORMA_VERSION;")"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Refusing: FORMA_VERSION is '$version' (want X.Y.Z in version.php)" >&2
  exit 1
fi

tag="v$version"
if git rev-parse "$tag" >/dev/null 2>&1; then
  echo "Refusing: tag $tag already exists. Bump version.php." >&2
  exit 1
fi

remote="$(git remote get-url origin)"
if [[ "$remote" != *Alta-forma/forma-cms* ]]; then
  echo "Refusing: origin is $remote — expected Alta-forma/forma-cms" >&2
  exit 1
fi

echo "Pushing main..."
git push origin main

echo "Tagging ${tag}..."
git tag -a "$tag" -m "Forma $version"
git push origin "$tag"

echo "Creating GitHub Release ${tag}..."
gh release create "$tag" \
  --repo Alta-forma/forma-cms \
  --title "Forma $version" \
  --generate-notes

echo
echo "Published https://github.com/Alta-forma/forma-cms/releases/tag/$tag"
echo "Now click Settings → Update on each live install. Do not rsync main."
