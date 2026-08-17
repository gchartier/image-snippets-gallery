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
fixture() { docker compose run --rm -T -v "$(pwd)/fixture.php:/fixture.php:ro" \
    cli wp --path=/var/www/html eval-file /fixture.php "$@" 2>/dev/null; }

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

head_ "Cold start: a never-synced gallery syncs inline on first view"

fixture reset >/dev/null
wp cache-enabler clear >/dev/null
assert "mirror is empty" "none" "$(fixture status gallery | awk '/^mirrored/{print $2}')"
COLD="$(fetch | grep -c '<figure class="isg-item"')"
if [ "$COLD" -gt 0 ]; then ok "first anonymous view rendered ${COLD} images from an inline sync"
else bad "first anonymous view rendered no images"; fi
assert "the view left the gallery mirrored" "$COLD" "$(fixture status gallery | awk '/^mirrored/{print $2}')"

head_ "The mirror is invisible"

assert "post type is not in the REST index" "0" \
    "$(curl -sS "${SITE}/wp-json/wp/v2/types" | grep -c isg_image)"
assert "post type is not in the sitemap" "0" \
    "$(curl -sS "${SITE}/wp-sitemap.xml" | grep -c isg_image)"
assert "no admin menu entry for it" "0" \
    "$(wp eval 'global $menu, $submenu; do_action("admin_menu"); echo (int) ( false !== strpos( wp_json_encode( array( $menu, $submenu ) ), "isg_image" ) );' | tr -d '\r')"
assert "not offered by the block inserter or editor REST" "0" \
    "$(wp eval 'echo (int) get_post_type_object("isg_image")->show_in_rest;' | tr -d '\r')"
assert "not in generic any-post-type queries" "0" \
    "$(wp eval 'echo count( array_filter( get_posts( array( "post_type" => "any", "posts_per_page" => 100 ) ), function( $p ) { return "isg_image" === $p->post_type; } ) );' | tr -d '\r')"

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

head_ "Sync corrects the mirror in both directions"

# Direction one: the site is missing an image ImageSnippets has. Drop one from
# the mirror and check Refresh all puts it back and the visitor sees it.
TRUE_COUNT="$(fetch | grep -c '<figure class="isg-item"')"
fixture drop gallery >/dev/null
DEGRADED="$(fetch | grep -c '<figure class="isg-item"')"

assert "fixture removed one image" "$((TRUE_COUNT - 1))" "$DEGRADED"

wp eval 'isg_refresh_all_galleries();' >/dev/null
assert "refresh all restored the gallery" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isg-item"')"

# Direction two: the site has an image ImageSnippets no longer has. This is
# the deletion path — detach, then hard-delete the orphan — run as cron would
# (no forced purge), so the purge-on-change is what reaches the visitor.
fixture ghost gallery >/dev/null
assert "fixture added a ghost image" "$((TRUE_COUNT + 1))" "$(fetch | grep -c '<figure class="isg-item"')"
fetch >/dev/null
assert "ghost is frozen in the page cache" "HIT" "$(cached)"

wp isg sync --cron >/dev/null
assert "an unforced sync removed it and purged for the visitor" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isg-item"')"
assert "the orphaned ghost was hard-deleted, not trashed" "0" \
    "$(wp post list --post_type=isg_image --post_status=any --s='Ghost image' --format=count | tr -d '\r')"

head_ "Sync refuses to act on a bad answer"

# An empty result for a gallery that had images is a failed sync, not a
# deletion. Cron must never empty a gallery on the word of one bad response.
EMPTY="$(fixture empty gallery)"
assert "empty result is refused" "refused:" "$(awk '/^sync/{print $2}' <<<"$EMPTY")"
assert "mirror is untouched" "${TRUE_COUNT} -> ${TRUE_COUNT}" "$(awk '/^mirrored/{print $2, $3, $4}' <<<"$EMPTY")"

ERR="$(wp eval 'print_r( isg_sync_gallery( "http://localhost:1/sparql", "hs_gallery02" )->get_error_code() );' | tr -d '\r')"
assert "unreachable endpoint is refused" "http_request_failed" "$ERR"
assert "and leaves no gallery label behind" "0" \
    "$(wp eval 'echo (int) ( isg_gallery_term( "http://localhost:1/sparql", "hs_gallery02" ) instanceof WP_Term );' | tr -d '\r')"
assert "mirror still renders" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isg-item"')"

head_ "Site search finds mirrored images"

SEARCH="$(fetch '/?s=pelican')"
assert "an anonymous search for a mirrored title finds it" "1" \
    "$(grep -c 'Brown Pelican' <<<"$SEARCH" | awk '{print ($1>0)?1:0}')"
assert "the hit links to the gallery page" "1" \
    "$(grep -o 'href="[^"]*/gallery/"[^>]*>Brown Pelican' <<<"$SEARCH" | wc -l | awk '{print ($1>0)?1:0}')"

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
    $rows = isg_gallery_rows( $a, isg_resolve_endpoint( $a ) );
    if ( is_wp_error( $rows ) ) { printf( "  FAIL  %s: %s\n", $p, $rows->get_error_message() ); continue; }
    $ld = isg_jsonld( $rows, "hs_gallery02", false, $p, "https://example.com/g/" );
    $sizes[ $p ] = strlen( $ld );
    printf( "  ....  %-11s %7d bytes  %6d gzipped\n", $p, strlen( $ld ), strlen( gzencode( $ld, 6 ) ) );
}
$ordered = ( $sizes["schema"] < $sizes["provenance"] && $sizes["provenance"] < $sizes["full"] );
printf( "  %s  profiles grow schema < provenance < full\n", $ordered ? "PASS" : "FAIL" );
$stored = 0; $og = 0;
foreach ( $rows as $r ) { foreach ( $r["triples"] as $t ) { $stored++; if ( false !== strpos( $t[1], "ogp.me" ) ) { $og++; } } }
printf( "  %s  mirror stores the untrimmed graph — %d triples, %d of them og:* — and trims at render\n", $og > 0 ? "PASS" : "FAIL", $stored, $og );
' )"
tally "$( printf '%s' "$PROFILES" | sed 's/PASS/\o033[32mPASS\o033[0m/; s/FAIL/\o033[31mFAIL\o033[0m/' )"

head_ "Freshness plumbing"

# WP-Cron only fires on traffic and this environment disables it on page loads,
# as managed hosts do. So the question is not "is something scheduled right
# now" — right after a refresh nothing is due, and asserting otherwise just
# tests leftovers. The question is whether a stale entry arms the machinery.

# Assert on the sync lock rather than on the cron array. wp_schedule_single_event
# de-duplicates identical events, so an event left pending by an earlier run makes
# the count stay flat even when this render did queue one. The lock is taken by
# isg_schedule_sync itself, so it is direct evidence rather than a side effect.
fixture age gallery >/dev/null
fetch >/dev/null   # a render past the interval should queue the sync
assert "a stale gallery queues a background sync" "held" \
    "$(fixture status gallery | awk '/^sync lock/{print $3}')"

wp cron event run --due-now >/dev/null
FRESH="$(fixture status gallery | awk '/^interval/{print $2}')"
assert "running cron makes it fresh again" "fresh" "$FRESH"

# The rung that matters most: a host where cron never runs at all. The reader
# must sync rather than serve stale, because a page cache would otherwise
# freeze that stale response for its own full lifetime.
fixture stall gallery >/dev/null
fetch >/dev/null
STALLED="$(fixture status gallery | awk '/^interval/{print $2}')"
assert "stalled cron falls back to a synchronous sync" "fresh" "$STALLED"

head_ "WP-CLI"

assert "wp isg status lists the gallery" "hs_gallery02" "$(wp isg status --format=csv | awk -F, 'NR==2{print $1}' | tr -d '\r')"
assert "wp isg sync reports counts" "1" "$(wp isg sync hs_gallery02 | grep -c 'images (+' )"

head_ "Sync status"
wp isg status | sed 's/^/       /'

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
