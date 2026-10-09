#!/bin/sh
# No npm dependencies: use an installed Chromium and the actual browser DOM.
set -eu
test_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
browser=${CHROMIUM:-chromium}
output=$(mktemp)
errors=$(mktemp)
trap 'rm -f "$output" "$errors"' EXIT HUP INT TERM
"$browser" --headless --no-sandbox --disable-gpu --disable-background-networking \
  --allow-file-access-from-files --virtual-time-budget=15000 --window-size="${KML_VIEWPORT:-1000,1200}" --dump-dom \
  "file://$test_dir/player.html" >"$output" 2>"$errors"
sed -n '/<pre id="results"/,/<\/pre>/p' "$output" | sed 's/<[^>]*>//g'
if ! grep -q 'data-test-result="passed"' "$output"; then
  cat "$errors" >&2
  exit 1
fi
