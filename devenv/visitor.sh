#!/usr/bin/env bash
#
# What an anonymous visitor actually sees.
#
# This is the whole point of the harness. Margaret is logged in, so the page
# cache bypasses for her and she sees fresh output. Her visitors get the stored
# HTML. Never judge freshness from a browser you are logged into.
#
# Usage: ./devenv/visitor.sh [path]      (default: /gallery/)

set -euo pipefail

PORT="${ISGAL_PORT:-8080}"
PATH_="${1:-/gallery/}"
URL="http://localhost:${PORT}${PATH_}"

# No cookies, no auth — exactly a first-time visitor.
#
# The Accept header is not cosmetic. Cache Enabler refuses to cache any response
# whose request Accept header lacks "text/html", and curl sends "*/*" by default,
# so a plain curl silently never gets cached and every request looks like a MISS.
# Same class of trap as testing while logged in: the tool changes the answer.
BODY="$(curl -sS --compressed \
    -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
    -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36' \
    "$URL")"

echo "URL            $URL"
echo "images in HTML $(grep -o '<figure class="isgal-item"' <<<"$BODY" | wc -l)"
echo "JSON-LD block  $(grep -c 'application/ld+json' <<<"$BODY" || true)"

# Cache Enabler stamps a signature comment into pages it served from disk.
if grep -q 'Cache Enabler by KeyCDN' <<<"$BODY"; then
    echo "page cache     HIT — this HTML came off disk, not from PHP"
    grep -o 'Cache Enabler by KeyCDN.*' <<<"$BODY" | head -1 | sed 's/^/               /'
else
    echo "page cache     MISS — PHP rendered this request"
fi

echo
echo "--- image URLs in the order the page lists them ---"
grep -o 'src="[^"]*"' <<<"$BODY" | sed -n 's/^src="\(.*\)"$/  \1/p' | head -50
