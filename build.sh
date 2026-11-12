#!/bin/sh
# Build the installation zip from the committed files only (git archive):
# no README, CHANGELOG, docs, tests, prompts or .DS_Store (see .gitattributes).
#
# Usage: ./build.sh            -> dist/abandonedcartadminnotifier-<version>.zip from HEAD
set -eu

cd "$(dirname "$0")"
NAME=abandonedcartadminnotifier
VERSION=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" "$NAME.php")

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Uncommitted changes: the zip is built from HEAD, commit first." >&2
    exit 1
fi

mkdir -p dist
OUT="dist/$NAME-$VERSION.zip"
rm -f "$OUT"
git archive --format=zip --prefix="$NAME/" -o "$OUT" HEAD

echo "$OUT"
unzip -l "$OUT" | tail -n +4 | sed '$d' | sed '$d' | awk '{print $4}'
