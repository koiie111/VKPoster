#!/bin/sh
# Builds resources/css/app.css with the Tailwind standalone CLI into public/assets/build/app.<hash>.css
# and writes manifest.json (read by View::asset()). `--check` fails when the built file is missing or stale.
set -eu
cd "$(dirname "$0")/.."
out=public/assets/build
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
tailwindcss -c tailwind.config.js -i resources/css/app.css -o "$tmp" --minify 2>/dev/null
hash=$(sha256sum "$tmp" | cut -c1-10)
name="app.$hash.css"
if [ "${1:-}" = "--check" ]; then
    if [ -f "$out/$name" ] && grep -q "\"$name\"" "$out/manifest.json" 2>/dev/null; then
        echo "CSS is up to date ($name)"
        exit 0
    fi
    echo "CSS is stale or missing: run 'make css'" >&2
    exit 1
fi
mkdir -p "$out"
find "$out" -name 'app.*.css' ! -name "$name" -delete
cp "$tmp" "$out/$name"
chmod 644 "$out/$name"
printf '{"app.css":"%s"}\n' "$name" > "$out/manifest.json"
echo "built $out/$name"
