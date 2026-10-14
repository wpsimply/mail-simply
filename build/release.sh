#!/bin/sh
#
# Build the release archive: only what a server needs to run Mail Simply.
#
# Usage: build/release.sh <version> [output-dir]
#
# Produces <output-dir>/mail-simply-<version>.zip (and a .sha256 next to it)
# with a single top-level mail-simply/ directory. Tests, examples, CI and
# every git file are left out; the storage directories are created empty.
set -eu

version="${1:?usage: build/release.sh <version> [output-dir]}"
version="${version#v}"
out_dir="${2:-dist}"

case "$version" in
    *[!0-9A-Za-z.+-]*|'') echo "Invalid version: $version" >&2; exit 1 ;;
esac

root="$(cd "$(dirname "$0")/.." && pwd)"

# The version lives in src/MailSimply.php; a tag that disagrees with it would
# ship an archive whose mail claims to come from another release.
declared="$(sed -n "s/.*const string VERSION = '\(.*\)';.*/\1/p" "$root/src/MailSimply.php")"

if [ "$declared" != "$version" ]; then
    echo "Tag $version does not match MailSimply::VERSION ($declared)." >&2
    exit 1
fi
mkdir -p "$out_dir"
out_dir="$(cd "$out_dir" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

app="$stage/mail-simply"
mkdir -p "$app"

# The runtime, and the files that tell someone how to configure it.
for path in bootstrap.php src public lang .env.example config.example.php LICENSE README.md; do
    cp -R "$root/$path" "$app/"
done


# Writable at runtime, empty in the archive.
mkdir -p "$app/storage/sessions" "$app/storage/users" "$app/storage/uploads" "$app/storage/throttle" "$app/storage/logs"
chmod 700 "$app/storage/sessions" "$app/storage/users" "$app/storage/uploads" "$app/storage/throttle" "$app/storage/logs"

# Nothing from a developer's machine or git may ride along.
find "$app" \( -name '.git*' -o -name '.DS_Store' -o -name '*.swp' \) -exec rm -rf {} +

archive="$out_dir/mail-simply-$version.zip"
rm -f "$archive"
(cd "$stage" && zip -qrX "$archive" mail-simply)

(cd "$out_dir" && if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$(basename "$archive")"
else
    shasum -a 256 "$(basename "$archive")"
fi > "$(basename "$archive").sha256")

echo "$archive"
