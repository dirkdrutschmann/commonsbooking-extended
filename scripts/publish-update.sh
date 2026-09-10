#!/usr/bin/env bash
set -euo pipefail
umask 077

PACKAGE_FILE="${1:-}"
SLUG='commonsbooking-extended'

[[ -n "$PACKAGE_FILE" && -f "$PACKAGE_FILE" ]] || { echo 'Package file is required.' >&2; exit 1; }
: "${UPDATE_SERVER_HOST:?UPDATE_SERVER_HOST is required}"
: "${UPDATE_SERVER_USER:?UPDATE_SERVER_USER is required}"
: "${UPDATE_SERVER_PACKAGE_DIR:?UPDATE_SERVER_PACKAGE_DIR is required}"
: "${UPDATE_SERVER_SSH_KEY_FILE:?UPDATE_SERVER_SSH_KEY_FILE is required}"
: "${UPDATE_SERVER_KNOWN_HOSTS_FILE:?UPDATE_SERVER_KNOWN_HOSTS_FILE is required}"

[[ "$UPDATE_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || { echo 'Unsafe update-server host.' >&2; exit 1; }
[[ "$UPDATE_SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Unsafe update-server user.' >&2; exit 1; }
[[ "$UPDATE_SERVER_PACKAGE_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo 'Unsafe update-server package directory.' >&2; exit 1; }
case "$UPDATE_SERVER_PACKAGE_DIR" in */../*|*/./*|*//* ) echo 'Non-canonical package directory.' >&2; exit 1 ;; esac

command -v ssh >/dev/null
command -v scp >/dev/null
if command -v sha256sum >/dev/null; then
    expected_hash="$(sha256sum "$PACKAGE_FILE" | awk '{print $1}')"
else
    expected_hash="$(shasum -a 256 "$PACKAGE_FILE" | awk '{print $1}')"
fi

ssh_args=(-i "$UPDATE_SERVER_SSH_KEY_FILE" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$UPDATE_SERVER_KNOWN_HOSTS_FILE" -o ConnectTimeout=15)
target="${UPDATE_SERVER_USER}@${UPDATE_SERVER_HOST}"

ssh "${ssh_args[@]}" "$target" bash -s -- "$UPDATE_SERVER_PACKAGE_DIR" <<'REMOTE_PREFLIGHT'
set -euo pipefail
package_dir="$1"
test -d "$package_dir"
test ! -L "$package_dir"
test "$(readlink -f -- "$package_dir")" = "$package_dir"
test -w "$package_dir"
REMOTE_PREFLIGHT

run_id="${GITHUB_RUN_ID:-manual}"
[[ "$run_id" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Unsafe workflow run id.' >&2; exit 1; }
incoming=".${SLUG}.${run_id}.incoming.zip"
scp "${ssh_args[@]}" "$PACKAGE_FILE" "$target:$UPDATE_SERVER_PACKAGE_DIR/$incoming"

ssh "${ssh_args[@]}" "$target" bash -s -- "$UPDATE_SERVER_PACKAGE_DIR" "$SLUG" "$incoming" "$expected_hash" <<'REMOTE_PUBLISH'
set -euo pipefail
package_dir="$1"
slug="$2"
incoming="$3"
expected="$4"
case "$slug" in commonsbooking-extended) ;; *) exit 50 ;; esac
case "$incoming" in .commonsbooking-extended.*.incoming.zip) ;; *) exit 51 ;; esac
incoming_path="$package_dir/$incoming"
final_path="$package_dir/$slug.zip"
temporary_path="$package_dir/.$slug.install.$$"
trap 'rm -f -- "$incoming_path" "$temporary_path"' EXIT
test -f "$incoming_path"
if command -v sha256sum >/dev/null; then
    actual="$(sha256sum "$incoming_path" | awk '{print $1}')"
else
    actual="$(shasum -a 256 "$incoming_path" | awk '{print $1}')"
fi
test "$actual" = "$expected"
install -m 0644 "$incoming_path" "$temporary_path"
mv -f "$temporary_path" "$final_path"
rm -f -- "$incoming_path"
REMOTE_PUBLISH

printf 'Published %s to https://updates.drutschmann.dev/\n' "$SLUG"
