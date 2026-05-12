#!/bin/sh

set -eu

BUMP="${1:-patch}"
DRY_RUN="${DRY_RUN:-0}"

case "$BUMP" in
  patch|minor|major)
    ;;
  *)
    echo "Usage: $0 [patch|minor|major]" >&2
    exit 1
    ;;
esac

if [ -n "$(git status --porcelain)" ]; then
  echo "Working tree is dirty. Commit or stash changes before tagging." >&2
  exit 1
fi

LATEST_TAG="$(git tag --list 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)"
if [ -z "$LATEST_TAG" ]; then
  LATEST_TAG="v0.0.0"
fi

VERSION="${LATEST_TAG#v}"
MAJOR="$(printf '%s' "$VERSION" | cut -d. -f1)"
MINOR="$(printf '%s' "$VERSION" | cut -d. -f2)"
PATCH="$(printf '%s' "$VERSION" | cut -d. -f3)"

case "$BUMP" in
  patch)
    NEXT_TAG="v${MAJOR}.${MINOR}.$((PATCH + 1))"
    ;;
  minor)
    NEXT_TAG="v${MAJOR}.$((MINOR + 1)).0"
    ;;
  major)
    NEXT_TAG="v$((MAJOR + 1)).0.0"
    ;;
esac

if git rev-parse -q --verify "refs/tags/$NEXT_TAG" >/dev/null; then
  echo "Tag $NEXT_TAG already exists." >&2
  exit 1
fi

if [ "$DRY_RUN" = "1" ]; then
  echo "Latest tag: $LATEST_TAG"
  echo "Next tag: $NEXT_TAG"
  exit 0
fi

git tag "$NEXT_TAG"
git push origin "$NEXT_TAG"
echo "Pushed $NEXT_TAG"
