#!/usr/bin/env bash
set -euo pipefail
umask 022

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
OUTPUT_DIR="${1:-$ROOT_DIR/dist}"
if [[ "$OUTPUT_DIR" != /* ]]; then
    OUTPUT_DIR="$ROOT_DIR/$OUTPUT_DIR"
fi

command -v npm >/dev/null || { echo 'npm is required.' >&2; exit 1; }
command -v rsync >/dev/null || { echo 'rsync is required.' >&2; exit 1; }
command -v zip >/dev/null || { echo 'zip is required.' >&2; exit 1; }

cd "$ROOT_DIR"
npm run audit:production

version="$(sed -nE 's/^Version:[[:space:]]*([^[:space:]]+).*/\1/p' cb-additional-features.php | head -n 1)"
[[ "$version" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Invalid plugin version.' >&2; exit 1; }

mkdir -p "$OUTPUT_DIR"
find "$OUTPUT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
build_root="$(mktemp -d)"
trap 'rm -rf "$build_root"' EXIT
stage_parent="$build_root/package"
stage="$stage_parent/cb-additional-features"
mkdir -p "$stage_parent"

rsync -a \
    --exclude '.git/' \
    --exclude '.github/' \
    --exclude '.DS_Store' \
    --exclude '.gitignore' \
    --exclude 'README.md' \
    --exclude 'dist/' \
    --exclude '.env*' \
    --exclude '*.log' \
    --exclude 'tests/' \
    --exclude 'scripts/' \
    --exclude 'package.json' \
    --exclude 'package-lock.json' \
    "$ROOT_DIR/" "$stage/"

find "$stage" -type d -exec chmod 0755 {} +
find "$stage" -type f -exec chmod 0644 {} +
find "$stage" -exec touch -t 198001010000 {} +

archive="$OUTPUT_DIR/cb-additional-features.zip"
(
    cd "$stage_parent"
    find cb-additional-features -print | LC_ALL=C sort | zip -X -q "$archive" -@
)

unzip -tq "$archive" >/dev/null
printf 'Created %s (%s)\n' "$archive" "$version"
