#!/usr/bin/env bash
#
# Everything that can be checked without a human driving a browser.
#
# Run it after any change to the plugin. It exercises the real endpoint, the
# real page cache and the real REST routes — nothing here is mocked, so a pass
# means the behaviour actually happened rather than that a unit test agreed
# with itself.
#
# What it deliberately does NOT cover: the editor's Refresh button as a button.
# The route behind it is checked here; the React that calls it is not. See
# Test A in README.md.

set -uo pipefail
cd "$(dirname "$0")"

PORT="${ISG_PORT:-8080}"
SITE="http://localhost:${PORT}"
PASS=0
FAIL=0

wp() { docker compose run --rm -T cli wp --path=/var/www/html "$@" 2>/dev/null; }

ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# Fold an external checker's PASS/FAIL lines into the running totals. Takes the
# output as an argument rather than on a pipe: a piped tally would run in a
# subshell and its increments would be discarded.
tally() {
    printf '%s\n' "$1"
    PASS=$((PASS + $(grep -c 'PASS' <<<"$1")))
    FAIL=$((FAIL + $(grep -c 'FAIL' <<<"$1")))
}

# assert <description> <expected> <actual>
assert() {
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected [$2], got [$3]"; fi
}

# Browser-shaped request. Cache Enabler declines to cache anything whose request
# did not ask for text/html, so a bare curl would make the page cache invisible.
fetch() {
    curl -sS --compressed \
        -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
        -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36' \
        "${SITE}${1:-/gallery/}"
}

cached() { fetch "$@" | grep -q 'Cache Enabler by KeyCDN' && echo HIT || echo MISS; }

head_ "Environment"

for svc in db redis wordpress cron; do
    state="$(docker compose ps --format '{{.Service}} {{.State}}' | awk -v s="$svc" '$1==s{print $2}')"
    assert "container ${svc} is running" "running" "${state:-absent}"
done

assert "our plugin is active"   "1" "$(wp plugin is-active image-snippets-gallery >/dev/null && echo 1 || echo 0)"
assert "page cache is active"   "1" "$(wp plugin is-active cache-enabler >/dev/null && echo 1 || echo 0)"
assert "object cache connected" "Connected" "$(wp redis status | awk -F': *' '/^Status/{print $2; exit}' | tr -d '\r')"
assert "cron is off on page loads (as on managed hosts)" "1" \
    "$(wp eval 'echo ( defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON ) ? 1 : 0;' | tr -d '\r')"

head_ "Page cache behaves like a page cache"

wp cache-enabler clear >/dev/null
assert "first anonymous request misses"  "MISS" "$(cached)"
assert "second anonymous request hits"   "HIT"  "$(cached)"

head_ "Purge reaches the cache"

POST_ID="$(wp post list --post_type=page --name=gallery --field=ID | tr -d '\r')"
ADAPTERS="$(wp eval "echo implode( ',', isg_purge_page_cache( array( ${POST_ID} ) ) );" | tr -d '\r')"

if [ -n "$ADAPTERS" ]; then ok "an adapter matched: ${ADAPTERS}"
else bad "no adapter matched — this site would go stale silently"; fi

assert "purge emptied the cached page" "MISS" "$(cached)"

head_ "Manual refresh purges even when nothing changed"

APP="$(wp user application-password create admin "verify-$$" --porcelain | tr -d '\r' | tail -1)"
fetch >/dev/null; fetch >/dev/null
assert "cache warm before refresh" "HIT" "$(cached)"

REFRESH="$(curl -sS -u "admin:${APP}" -X POST "${SITE}/wp-json/imagesnippets/v1/refresh" \
    -H 'Content-Type: application/json' \
    -d '{"attributes":{"gallery":"hs_gallery02","limit":12}}')"

if grep -q '"refreshed":true' <<<"$REFRESH"; then ok "refresh route returned success: ${REFRESH}"
else bad "refresh route failed: ${REFRESH}"; fi

assert "refresh purged the page cache" "MISS" "$(cached)"

head_ "Refresh all targets the queries actually in use"

# A gallery name alone does not determine the query — the limit, sort, user
# filter, endpoint and payload profile all feed the cache key. Rebuilding
# attributes from defaults refreshes an entry no page reads, and the symptom is
# a refresh that reports success while the site stays stale. So this drops an
# image from the stored copy and checks that Refresh all actually puts it back.
TRUE_COUNT="$(fetch | grep -c '<figure class="isg-item"')"
./simulate-change.sh >/dev/null 2>&1
DEGRADED="$(fetch | grep -c '<figure class="isg-item"')"

assert "fixture removed one image" "$((TRUE_COUNT - 1))" "$DEGRADED"

wp eval 'isg_refresh_all_galleries();' >/dev/null
assert "refresh all restored the gallery" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isg-item"')"

head_ "Editor preview renders server-side"

SSR="$(curl -sS -u "admin:${APP}" -G \
    --data-urlencode 'context=edit' \
    --data-urlencode 'attributes[gallery]=hs_gallery02' \
    --data-urlencode 'attributes[limit]=6' \
    "${SITE}/wp-json/wp/v2/block-renderer/imagesnippets/gallery")"

tally "$( printf '%s' "$SSR" | python3 ./check-ssr.py )"

head_ "JSON-LD on the public page"

tally "$( fetch | python3 ./check-jsonld.py )"

head_ "Payload profiles"

PROFILES="$( wp eval '
$sizes = array();
foreach ( array( "schema", "provenance", "full" ) as $p ) {
    $a    = isg_resolve_attributes( array( "gallery" => "hs_gallery02", "limit" => 12, "jsonldProfile" => $p ) );
    $rows = isg_fetch_rows( isg_resolve_endpoint( $a ), isg_build_sparql( $a ) );
    if ( is_wp_error( $rows ) ) { printf( "  FAIL  %s: %s\n", $p, $rows->get_error_message() ); continue; }
    $ld = isg_jsonld( $rows, "hs_gallery02", false, $p, "https://example.com/g/" );
    $sizes[ $p ] = strlen( $ld );
    printf( "  ....  %-11s %7d bytes  %6d gzipped\n", $p, strlen( $ld ), strlen( gzencode( $ld, 6 ) ) );
}
$ordered = ( $sizes["schema"] < $sizes["provenance"] && $sizes["provenance"] < $sizes["full"] );
printf( "  %s  profiles grow schema < provenance < full\n", $ordered ? "PASS" : "FAIL" );
' )"
tally "$( printf '%s' "$PROFILES" | sed 's/PASS/\o033[32mPASS\o033[0m/; s/FAIL/\o033[31mFAIL\o033[0m/' )"

head_ "Freshness plumbing"

SCHEDULED="$(wp cron event list --fields=hook --format=csv | grep -c isg || true)"
if [ "$SCHEDULED" -gt 0 ]; then ok "a refresh is scheduled (${SCHEDULED} event(s))"
else bad "nothing scheduled — cron path is not armed"; fi

wp eval 'print_r( isg_sync_status() );' | sed 's/^/       /'

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
