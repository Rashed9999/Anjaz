#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
root=$(git rev-parse --show-toplevel)
tool=.cache/floorplan
revision=94f81b463b7617e06d873ac71323ce71644c8399
if [[ ! -d "$tool/.git" ]]; then
    mkdir -p .cache
    git clone --no-checkout https://github.com/haydenschmitty/floorplan.git "$tool"
fi
git -C "$tool" checkout --quiet --detach "$revision"
scripts="$tool/skills/floorplan/scripts"
out="$root/floorplan"
mkdir -p "$out"
cp architecture.json "$out/architecture.json"
node "$scripts/scan.mjs" "$root" --unattended --json > .cache/scan.json
node "$scripts/build.mjs" "$root" --unattended --json > .cache/build.json
./node_modules/.bin/tailwindcss -i style.css -o "$out/overview.css" --minify
node --import tsx overview.tsx > "$out/overview.html"
