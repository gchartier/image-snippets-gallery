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

PORT="${ISGAL_PORT:-8080}"
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
COLD="$(fetch | grep -c '<figure class="isgal-item"')"
if [ "$COLD" -gt 0 ]; then ok "first anonymous view rendered ${COLD} images from an inline sync"
else bad "first anonymous view rendered no images"; fi
assert "the view left the gallery mirrored" "$COLD" "$(fixture status gallery | awk '/^mirrored/{print $2}')"

head_ "The mirror is invisible"

assert "post type is not in the REST index" "0" \
    "$(curl -sS "${SITE}/wp-json/wp/v2/types" | grep -c isgal_image)"
assert "post type is not in the sitemap" "0" \
    "$(curl -sS "${SITE}/wp-sitemap.xml" | grep -c isgal_image)"
assert "no admin menu entry for it" "0" \
    "$(wp eval 'global $menu, $submenu; do_action("admin_menu"); echo (int) ( false !== strpos( wp_json_encode( array( $menu, $submenu ) ), "isgal_image" ) );' | tr -d '\r')"
assert "not offered by the block inserter or editor REST" "0" \
    "$(wp eval 'echo (int) get_post_type_object("isgal_image")->show_in_rest;' | tr -d '\r')"
assert "not in generic any-post-type queries" "0" \
    "$(wp eval 'echo count( array_filter( get_posts( array( "post_type" => "any", "posts_per_page" => 100 ) ), function( $p ) { return "isgal_image" === $p->post_type; } ) );' | tr -d '\r')"

head_ "Page cache behaves like a page cache"

wp cache-enabler clear >/dev/null
assert "first anonymous request misses"  "MISS" "$(cached)"
assert "second anonymous request hits"   "HIT"  "$(cached)"

head_ "Purge reaches the cache"

POST_ID="$(wp post list --post_type=page --name=gallery --field=ID | tr -d '\r')"
ADAPTERS="$(wp eval "echo implode( ',', isgal_purge_page_cache( array( ${POST_ID} ) ) );" | tr -d '\r')"

if [ -n "$ADAPTERS" ]; then ok "an adapter matched: ${ADAPTERS}"
else bad "no adapter matched — this site would go stale silently"; fi

assert "purge emptied the cached page" "MISS" "$(cached)"

head_ "Manual refresh purges even when nothing changed"

APP="$(wp user application-password create admin "verify-$$" --porcelain | tr -d '\r' | tail -1)"
fetch >/dev/null; fetch >/dev/null
assert "cache warm before refresh" "HIT" "$(cached)"

REFRESH="$(curl -sS -u "admin:${APP}" -X POST "${SITE}/wp-json/imagesnippets/v1/refresh" \
    -H 'Content-Type: application/json' \
    -d '{"attributes":{"gallery":"mmgallery01","limit":12}}')"

if grep -q '"refreshed":true' <<<"$REFRESH"; then ok "refresh route returned success: ${REFRESH}"
else bad "refresh route failed: ${REFRESH}"; fi

assert "refresh purged the page cache" "MISS" "$(cached)"

head_ "Sync corrects the mirror in both directions"

# Direction one: the site is missing an image ImageSnippets has. Drop one from
# the mirror and check Refresh all puts it back and the visitor sees it.
TRUE_COUNT="$(fetch | grep -c '<figure class="isgal-item"')"
fixture drop gallery >/dev/null
DEGRADED="$(fetch | grep -c '<figure class="isgal-item"')"

assert "fixture removed one image" "$((TRUE_COUNT - 1))" "$DEGRADED"

wp eval 'isgal_refresh_all_galleries();' >/dev/null
assert "refresh all restored the gallery" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isgal-item"')"

# Direction two: the site has an image ImageSnippets no longer has. This is
# the deletion path — detach, then hard-delete the orphan — run as cron would
# (no forced purge), so the purge-on-change is what reaches the visitor.
fixture ghost gallery >/dev/null
assert "fixture added a ghost image" "$((TRUE_COUNT + 1))" "$(fetch | grep -c '<figure class="isgal-item"')"
fetch >/dev/null
assert "ghost is frozen in the page cache" "HIT" "$(cached)"

wp isgal sync --cron >/dev/null
assert "an unforced sync removed it and purged for the visitor" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isgal-item"')"
assert "the orphaned ghost was hard-deleted, not trashed" "0" \
    "$(wp post list --post_type=isgal_image --post_status=any --s='Ghost image' --format=count | tr -d '\r')"

head_ "Sync refuses to act on a bad answer"

# An empty result for a gallery that had images is a failed sync, not a
# deletion. Cron must never empty a gallery on the word of one bad response.
EMPTY="$(fixture empty gallery)"
assert "empty result is refused" "refused:" "$(awk '/^sync/{print $2}' <<<"$EMPTY")"
assert "mirror is untouched" "${TRUE_COUNT} -> ${TRUE_COUNT}" "$(awk '/^mirrored/{print $2, $3, $4}' <<<"$EMPTY")"

ERR="$(wp eval 'print_r( isgal_sync_gallery( "http://localhost:1/sparql", "mmgallery01" )->get_error_code() );' | tr -d '\r')"
assert "unreachable endpoint is refused" "http_request_failed" "$ERR"
assert "and leaves no gallery label behind" "0" \
    "$(wp eval 'echo (int) ( isgal_gallery_term( "http://localhost:1/sparql", "mmgallery01" ) instanceof WP_Term );' | tr -d '\r')"
assert "mirror still renders" "$TRUE_COUNT" "$(fetch | grep -c '<figure class="isgal-item"')"

head_ "Site search finds mirrored images"

SEARCH="$(fetch '/?s=carburetor')"
assert "an anonymous search for a mirrored title finds it" "1" \
    "$(grep -c 'Carburetor Abstract' <<<"$SEARCH" | awk '{print ($1>0)?1:0}')"
assert "the hit links to the gallery page" "1" \
    "$(grep -o 'href="[^"]*/gallery/#isgal-[a-f0-9]*"[^>]*>Carburetor Abstract' <<<"$SEARCH" | wc -l | awk '{print ($1>0)?1:0}')"

# The fragment is the point of the link. A mirror post has no page of its own,
# so every image in a gallery resolves to the same permalink; without the
# anchor a search matching a dozen of them returns a dozen identical results
# that all dump the visitor at the top of the grid. Resolve the fragment
# against the rendered page rather than trusting that both sides agree — one
# naming an id the page never renders would scroll nowhere and fail silently.
FRAG="$(grep -o 'href="[^"]*/gallery/#isgal-[a-f0-9]*"[^>]*>Carburetor Abstract' <<<"$SEARCH" \
    | head -1 | sed 's|.*/gallery/#||; s|".*||')"
assert "the fragment resolves to that image on the page" "1" \
    "$(grep -c "id=\"${FRAG:-__missing__}\"" <<<"$(fetch)" | awk '{print ($1>0)?1:0}')"

# Anchors are derived from the image IRI on both sides, so a collision would
# silently point several results at one image.
assert "every rendered image has a distinct anchor" "$TRUE_COUNT" \
    "$(fetch | grep -o 'id="isgal-[a-f0-9]*"' | sort -u | wc -l)"

# A mirror post has no attachment, so wp_get_attachment_image() never runs and
# everything it would have contributed has to come from our filter instead. The
# theme asks through $size and $attr; ignoring either is what made these render
# at natural size and spill over the title beneath them.
IMG="$(grep -o '<img[^>]*wp-post-image[^>]*>' <<<"$SEARCH" | head -1)"
assert "the thumbnail honours the caller's layout request" "1" \
    "$(grep -c 'object-fit:cover' <<<"$IMG" | awk '{print ($1>0)?1:0}')"
assert "the thumbnail carries core's size classes" "1" \
    "$(grep -c 'attachment-post-thumbnail size-post-thumbnail' <<<"$IMG" | awk '{print ($1>0)?1:0}')"
assert "the thumbnail declares a real width" "1" \
    "$(grep -c 'width="[0-9][0-9]*"' <<<"$IMG" | awk '{print ($1>0)?1:0}')"
assert "the thumbnail declares a real height" "1" \
    "$(grep -c 'height="[0-9][0-9]*"' <<<"$IMG" | awk '{print ($1>0)?1:0}')"

# post_content holds the index text — every entity label and keyword the graph
# carries — which is what lets an unrelated word find the image. It has to keep
# matching without being printed; these two assertions only mean anything as a
# pair. "pinhole" is a graph label on the carburetor that appears in no prose.
assert "a graph keyword still matches an image whose prose never says it" "1" \
    "$(fetch '/?s=pinhole' | grep -c 'Carburetor Abstract' | awk '{print ($1>0)?1:0}')"
assert "but the keyword list is not printed as the result body" "0" \
    "$(grep -c 'pinhole' <<<"$SEARCH")"

# core/post-date reads through the core/post-data binding, which refuses any
# post that is not publicly viewable — which mirror posts deliberately are not.
# Every other result in Twenty Twenty-Five's search carries a date; without an
# answer to that binding, ours were the only ones that did not.
assert "the theme's own date block renders for an image result" "1" \
    "$(grep -c 'class="wp-block-post-date[^"]*"><a href="[^"]*#isgal-' <<<"$SEARCH" | awk '{print ($1>0)?1:0}')"

# The photographer is not a user of this site and post_author is 0, so a theme
# that prints an author gets it from the graph or gets nothing.
assert "a theme that prints an author gets the photographer" "Margaret Warren" \
    "$(wp eval '$q = new WP_Query( array( "post_type" => "isgal_image", "posts_per_page" => 1, "s" => "pinhole" ) ); $q->the_post(); echo get_the_author();' | tr -d '\r')"

head_ "Editor preview renders server-side"

SSR="$(curl -sS -u "admin:${APP}" -G \
    --data-urlencode 'context=edit' \
    --data-urlencode 'attributes[gallery]=mmgallery01' \
    --data-urlencode 'attributes[limit]=6' \
    "${SITE}/wp-json/wp/v2/block-renderer/imagesnippets/gallery")"

tally "$( printf '%s' "$SSR" | python3 ./check-ssr.py )"

head_ "Justified layout is sized on the server"

# Each item carries its own width/height ratio from the mirrored og:image
# dimensions, so rows are laid out before any image loads and without script.
JUSTHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "layout" => "justified", "limit" => 12 ) );' | tr -d '\r')"
assert "justified items carry a per-image ratio" "6" "$(grep -o 'style="--isgal-r:[0-9.]*"' <<<"$JUSTHTML" | wc -l)"
assert "and at least one is not the 4:3 fallback" "1" "$(grep -o 'style="--isgal-r:[0-9.]*"' <<<"$JUSTHTML" | grep -vc 'r:1.3333' | awk '{print ($1>0)?1:0}')"
assert "the wrapper is marked justified" "1" "$(grep -c 'isgal-layout-justified' <<<"$JUSTHTML")"

head_ "Caption lines come from the graph"

CAPHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "displayCaption" => true, "captionPosition" => "overlay", "captionFields" => array( "creator", "title", "date", "tags", "rights" ), "hoverEffect" => "zoom", "limit" => 12 ) );' | tr -d '\r')"
assert "creator line is the resolved dc:creator label" "6" "$(grep -o 'isgal-cap-creator">Margaret Warren<' <<<"$CAPHTML" | wc -l)"
assert "the stored order is the rendered order (creator before title)" "1" "$(tr -d '\n\t' <<<"$CAPHTML" | grep -o 'isgal-cap-creator">[^<]*</span> *<span class="isgal-cap isgal-cap-title"' | head -1 | wc -l)"
assert "a year-only date stays a year" "1" "$(grep -c 'isgal-cap-date"[^>]*>2002<' <<<"$CAPHTML")"
assert "tags are not a caption line (a stored 'tags' field is ignored)" "0" "$(grep -c 'isgal-cap-tags' <<<"$CAPHTML")"
ABOVE="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "displayCaption" => true, "captionPosition" => "above", "limit" => 12 ) );' | tr -d '\r\n\t')"
assert "captions above: the figcaption comes before the link in every figure" "6" "$(grep -o '<figure[^>]*> *<figcaption' <<<"$ABOVE" | wc -l)"
assert "and the wrapper says so" "1" "$(grep -c 'isgal-captions-above' <<<"$ABOVE")"
assert "position and hover effect are wrapper classes" "1" "$(grep -c 'isgal-captions-overlay isgal-hover-zoom' <<<"$CAPHTML")"

head_ "Lightbox is server-fed"

LBHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "onClick" => "lightbox", "limit" => 12 ) );' | tr -d '\r')"
assert "the wrapper joins the Interactivity store" "1" "$(grep -c 'data-wp-interactive="imagesnippets/gallery"' <<<"$LBHTML")"
assert "every image is in the context, so opening one needs no request" "6" "$(grep -o 'data-wp-context="[^"]*"' <<<"$LBHTML" | grep -o '&quot;anchor&quot;' | wc -l)"
assert "the context carries provenance (rights) for the panel" "1" "$(grep -o 'data-wp-context="[^"]*"' <<<"$LBHTML" | grep -c 'Copyright 2022 Margaret Warren')"
assert "the lightbox has no tags row" "0" "$(grep -c 'state.current.tags' <<<"$LBHTML")"
assert "the back of every image rides along as inert JSON, outside the context" "6" "$(grep -o '<script type="application/json" class="isgal-backs">.*</script>' <<<"$LBHTML" | grep -o '"isgal-[0-9a-f]\{32\}":' | wc -l)"
assert "the back names things by their labels and keeps regions" "1" "$(grep -o 'class="isgal-backs">.*</script>' <<<"$LBHTML" | grep -c '"Looks like".*"Eagle"')"
assert "page chrome (Open Graph) stays off the back" "0" "$(grep -o 'class="isgal-backs">.*</script>' <<<"$LBHTML" | grep -c 'ogp.me\|og:image')"
NOFLIP="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "onClick" => "lightbox", "lightboxFlip" => false, "limit" => 12 ) );' | tr -d '\r')"
assert "the flip can be turned off, and then costs nothing" "0" "$(grep -c 'isgal-backs\|isgal-flip__back' <<<"$NOFLIP")"
assert "the lightbox says where the metadata is hosted, with ImageSnippets a link to the image's page" "1" "$(tr -d '\n\t' <<<"$LBHTML" | grep -c 'Image metadata is hosted at <a data-wp-bind--href="state.current.page"[^>]*>ImageSnippets</a>')"
assert "the back turns over again on a click" "1" "$(grep -c 'isgal-flip__back"[^>]*data-wp-on--click="actions.flipBack"' <<<"$LBHTML")"
assert "lightbox keys are heard on the document, so they work wherever focus is" "1" "$(grep -c 'data-wp-on-document--keydown="actions.keydown"' <<<"$LBHTML")"
assert "one dialog per gallery" "1" "$(grep -c '<dialog class="isgal-lightbox"' <<<"$LBHTML")"
assert "links still point at ImageSnippets for crawlers" "6" "$(grep -o '<a href="https://imagesnippets.com/[^"]*"[^>]*data-wp-on--click="actions.open"' <<<"$LBHTML" | wc -l)"
assert "the view module is enqueued on the page" "1" "$(fetch | grep -c 'build/view.js')"
NOLB="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "linkNewTab" => false, "limit" => 2 ) );' | tr -d '\r')"
assert "open-in-new-tab can be turned off" "0" "$(grep -c 'target="_blank"' <<<"$NOLB")"

head_ "Images load in a sensible order"

LDHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "columns" => 2, "limit" => 12 ) );' | tr -d '\r')"
assert "auto: the first two rows load with the page, the rest lazily" "4 eager 2 lazy" "$(echo "$(grep -c 'loading="eager"' <<<"$LDHTML") eager $(grep -c 'loading="lazy"' <<<"$LDHTML") lazy")"
assert "images carry their own dimensions where the graph knows them" "1" "$(grep -c 'width="1000" height="450"' <<<"$LDHTML")"
assert "fade-in is the default, and marks each image when it arrives" "6" "$(grep -c "onload=\"this.classList.add('is-loaded')\"" <<<"$LDHTML")"
LZHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "imageLoading" => "lazy", "imageReveal" => "none", "limit" => 12 ) );' | tr -d '\r')"
assert "lazy means all of them; no reveal means no onload" "6 0" "$(echo "$(grep -c 'loading="lazy"' <<<"$LZHTML") $(grep -c 'is-loaded' <<<"$LZHTML")")"
PGHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "imageLoading" => "eager", "pageSize" => 2, "limit" => 12 ) );' | tr -d '\r')"
assert "images waiting behind Load more stay lazy even when eager is chosen" "2 eager 4 lazy" "$(echo "$(grep -c 'loading="eager"' <<<"$PGHTML") eager $(grep -c 'loading="lazy"' <<<"$PGHTML") lazy")"

head_ "Saving a post never waits on ImageSnippets"

SAVEPROBE="$(wp eval 'define( "REST_REQUEST", true ); wp_set_current_user( 1 ); isgal_rest_request( new WP_REST_Request( "POST", "/wp/v2/pages/1" ) ); $w = isgal_is_rest_write(); $p = isgal_is_editor_preview(); isgal_rest_request( new WP_REST_Request( "GET", "/wp/v2/block-renderer/imagesnippets/gallery" ) ); echo (int) $w, (int) $p, (int) isgal_is_rest_write(), (int) isgal_is_editor_preview();' | tr -d '\r')"
assert "a save is a write and not a preview; the block renderer is the reverse" "1001" "$SAVEPROBE"

head_ "Dates are resolved, and say where from"

TLHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "layout" => "timeline", "order" => "asc", "displayCaption" => true, "limit" => 12 ) );' | tr -d '\r')"
assert "timeline groups by year, oldest first when ascending" "1983 2002 2007 2012 2017 2022" "$(grep -o 'isgal-timeline__label">[0-9]*' <<<"$TLHTML" | sed 's/.*>//' | tr '\n' ' ' | sed 's/ $//')"
assert "every timeline item carries a date line even with no date field chosen" "6" "$(grep -c 'isgal-cap-date"' <<<"$TLHTML")"
assert "the date line names its source" "6" "$(grep -c 'isgal-cap-date" title="Date created (IPTC/Photoshop DateCreated)"' <<<"$TLHTML")"
RESOLVED="$(wp eval '$row = array( "dates" => array( "DateTimeOriginal" => "2019:07:04 10:11:12", "DateCreated" => "2021-10-06" ), "rights" => "© 2012 X", "date" => "" ); $a = isgal_row_resolved_date( $row ); $b = isgal_row_resolved_date( $row, array( "DateCreated" ) ); $c = isgal_row_resolved_date( $row, array( "rights" ) ); echo $a["source"], ":", gmdate( "Y-m-d", $a["ts"] ), " ", $b["source"], " ", $c["raw"];' | tr -d '\r')"
assert "capture time wins by default, EXIF colons parsed; priority and rights-year honoured" "DateTimeOriginal:2019-07-04 DateCreated 2012" "$RESOLVED"
assert "the mirror keeps every date source per image" "1" "$(wp eval '$t = isgal_gallery_term( "https://imagesnippets.com/sparql/dbpedia", "mmgallery01" ); $ids = get_objects_in_term( $t->term_id, ISGAL_TAXONOMY ); $r = isgal_mirror_read_row( $ids[0] ); echo (int) ( isset( $r["dates"] ) && is_array( $r["dates"] ) && ! empty( $r["dates"] ) );' | tr -d '\r')"

head_ "Facets are built from the graph"

FCHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "facets" => array( "year", "tag", "rights", "creator", "camera" ), "facetMax" => 4, "limit" => 12 ) );' | tr -d '\r')"
assert "facets render in the block's order (year before rights); a stored tag facet is ignored" "isgal-facet-year isgal-facet-rights" "$(grep -o 'isgal-facet isgal-facet-[a-z]*' <<<"$FCHTML" | sed 's/isgal-facet //' | tr '\n' ' ' | sed 's/ $//')"
assert "a facet every image shares (creator) and one none has (camera) are left out" "0" "$(grep -c 'isgal-facet-creator\|isgal-facet-camera' <<<"$FCHTML")"
assert "years are chips with counts, newest first" "2022 2017 2012 2007" "$(grep -o 'facet&quot;:&quot;year&quot;,&quot;value&quot;:&quot;[0-9]*' <<<"$FCHTML" | sed 's/.*;//' | tr '\n' ' ' | sed 's/ $//')"
assert "chips are capped per facet, and a year chip carries a string, not a number" "4" "$(grep -o 'value&quot;:&quot;[0-9]*&quot;' <<<"$FCHTML" | wc -l)"
assert "every figure watches its place in the context and stays in the HTML" "6" "$(grep -c '<figure class="isgal-item"[^>]*data-wp-watch--hidden="callbacks.syncItem"' <<<"$FCHTML")"
assert "the context carries lowercased facet values per image" "1" "$(grep -o 'data-wp-context="[^"]*"' <<<"$FCHTML" | grep -c '&quot;year&quot;:\[&quot;2022&quot;\]')"
assert "filtering works without a lightbox (interactive wrapper, lightbox off)" "1" "$(grep -o 'data-wp-context="[^"]*"' <<<"$FCHTML" | head -1 | grep -c '&quot;lightbox&quot;:false')"
assert "the status line and empty message start hidden" "2" "$(grep -c 'class="isgal-facets__\(status\|empty\)[^"]*" hidden' <<<"$FCHTML")"
NOF="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "limit" => 2 ) );' | tr -d '\r')"
assert "no facets chosen: no bar, no interactivity" "0" "$(grep -c 'isgal-facets\|data-wp-interactive' <<<"$NOF")"
CAM="$(wp eval '$r = array( "triples" => array( array( "x", "http://ns.adobe.com/exif/1.0/Make", array( "type" => "literal", "value" => "Canon" ) ), array( "x", "http://ns.adobe.com/exif/1.0/Model", array( "type" => "literal", "value" => "Canon EOS 5D" ) ) ) ); echo isgal_row_camera( $r ), "|", isgal_row_camera( array( "triples" => array( array( "x", "http://ns.adobe.com/exif/1.0/Make", array( "type" => "literal", "value" => "NIKON" ) ), array( "x", "http://ns.adobe.com/exif/1.0/Model", array( "type" => "literal", "value" => "D700" ) ) ) ) );' | tr -d '\r')"
assert "camera joins Make and Model without repeating the make" "Canon EOS 5D|NIKON D700" "$CAM"
# The page is the harness's own, so a fresh environment has it too.
if ! wp post list --post_type=page --name=facets --format=count | grep -q '^1$'; then
    wp post create --post_type=page --post_status=publish --post_title='Facets' --post_name=facets \
        --post_content='<!-- wp:imagesnippets/gallery {"gallery":"mmgallery01","facets":["tag","year","creator","camera","rights"],"onClick":"lightbox","layout":"timeline","displayCaption":true,"limit":12} /-->' >/dev/null
fi
FPAGE="$(fetch /facets/)"
assert "the anonymous visitor gets the bar and the view module on a page-cached page" "2" "$(grep -c 'class="isgal-facets"\|build/view.js' <<<"$FPAGE")"

head_ "Slideshow is the whole gallery, one image at a time"

SSHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "layout" => "slideshow", "slideAutoplay" => true, "slideInterval" => 3, "facets" => array( "year" ), "limit" => 12 ) );' | tr -d '\r')"
assert "every figure is in the page" "6" "$(grep -c '<figure class="isgal-item"' <<<"$SSHTML")"
assert "all but the first carry hidden from the server (no flash before scripts run)" "5" "$(grep -c '<figure class="isgal-item"[^>]* hidden' <<<"$SSHTML")"
assert "each figure watches the slide cursor, not just the filters" "6" "$(grep -c 'data-wp-watch--hidden="callbacks.syncSlide"' <<<"$SSHTML")"
assert "the first slide loads eagerly, the rest lazily" "1 5" "$(echo "$(grep -c 'loading="eager"' <<<"$SSHTML") $(grep -c 'loading="lazy"' <<<"$SSHTML")")"
assert "the column count is one, whatever the block says" "1" "$(grep -c 'isgal-cols:1;' <<<"$SSHTML")"
assert "the wrapper is a carousel region that owns keys, hover and the autoplay watch" "1" "$(grep -c 'aria-roledescription="carousel"[^>]*' <<<"$SSHTML")$(grep -q 'data-wp-watch--autoplay="callbacks.autoplay"' <<<"$SSHTML" || echo x)"
assert "a dot per image, the first selected, each bound to the filters" "6 1" "$(echo "$(grep -c 'class="isgal-slides__dot"[^>]*data-wp-bind--hidden="state.itemHidden"' <<<"$SSHTML") $(grep -c 'class="isgal-slides__dot"[^>]*aria-selected="true"' <<<"$SSHTML")")"
assert "prev, next and play controls are present when autoplay is on" "3" "$(grep -c 'actions.slidePrev\|actions.slideNext\|actions.togglePlay' <<<"$SSHTML")"
assert "the context carries the interval in ms and starts playing" "1" "$(grep -o 'data-wp-context="[^"]*"' <<<"$SSHTML" | head -1 | grep -c '&quot;interval&quot;:3000,&quot;playing&quot;:true')"
assert "the counter starts at 1 / 6 before any script" "1" "$(grep -c 'isgal-slides__count[^>]*>1 / 6<' <<<"$SSHTML")"
assert "JSON-LD still describes every image" "6" "$(grep -o '<script type="application/ld+json">.*</script>' <<<"$SSHTML" | grep -o '"contentUrl"' | wc -l)"
SSTH="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "layout" => "slideshow", "slideNav" => "thumbnails", "limit" => 12 ) );' | tr -d '\r')"
assert "thumbnail picker: the small mirrored thumbnail per image, not the original; no play button" "6 0" "$(echo "$(tr -d '\n\t' <<<"$SSTH" | grep -o 'isgal-slides__thumb"[^>]*> *<img src="[^"]*/thumbnails/[^"]*"' | wc -l) $(grep -c 'actions.togglePlay' <<<"$SSTH")")"
SSNONE="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "layout" => "slideshow", "slideNav" => "none", "limit" => 12 ) );' | tr -d '\r')"
assert "no picker: arrows and counter only" "1 0" "$(echo "$(grep -c 'isgal-slides__controls' <<<"$SSNONE") $(grep -c 'role="tablist"' <<<"$SSNONE")")"
if ! wp post list --post_type=page --name=slideshow --format=count | grep -q '^1$'; then
    wp post create --post_type=page --post_status=publish --post_title='Slideshow' --post_name=slideshow \
        --post_content='<!-- wp:imagesnippets/gallery {"gallery":"mmgallery01","layout":"slideshow","slideAutoplay":true,"slideNav":"thumbnails","onClick":"lightbox","facets":["year"],"displayCaption":true,"limit":12} /-->' >/dev/null
fi
SPAGE="$(fetch /slideshow/)"
assert "the anonymous visitor gets one visible slide, the controls and the view module" "1 1 1" "$(echo "$(grep -c '<figure class="isgal-item"[^>]*id="isgal-[a-f0-9]*"[^>]*"callbacks.syncSlide" vocab' <<<"$SPAGE" | awk '{print ($1>0)?1:0}') $(grep -c 'class="isgal-slides__controls"' <<<"$SPAGE") $(grep -c 'build/view.js' <<<"$SPAGE")")"
# The server runs directives too; a bind on derived state would have taken the
# attribute off before any script ran, and every slide would flash up at once.
assert "the server-rendered hidden survives directive processing on the real page" "5" "$(grep -c '<figure class="isgal-item"[^>]* hidden' <<<"$SPAGE")"

head_ "A shared gallery page has a picture"

# Core writes no Open Graph tags, and an SEO plugin finds no image in a page
# whose gallery is rendered at request time: the first image has to be offered.
OGIMG="$(grep -o '<meta property="og:image" content="[^"]*"' <<<"$SPAGE" | sed 's/.*content="//; s/"$//')"
assert "the page names exactly one share image" "1" "$(grep -c '<meta property="og:image" content=' <<<"$SPAGE")"
assert "it is the full-size image of the first slide, not a thumbnail" "1" "$(tr -d '\n' <<<"$SPAGE" | grep -o '<figure class="isgal-item"[^>]*>.\{0,1200\}' | head -1 | grep -cF "$OGIMG")"
assert "with a title, a URL and the large-card hint" "1 1 1" "$(echo "$(grep -c '<meta property="og:title" content="Slideshow"' <<<"$SPAGE") $(grep -c '<meta property="og:url" content="[^"]*/slideshow/"' <<<"$SPAGE") $(grep -c '<meta name="twitter:card" content="summary_large_image"' <<<"$SPAGE")")"
assert "a page with no gallery is left alone" "0" "$(fetch / | grep -c 'ImageSnippets Gallery: Open Graph')"
assert "the filter turns it off" "0" "$(wp eval 'add_filter( "isgal_emit_open_graph", "__return_false" ); $p = get_page_by_path( "slideshow" ); query_posts( array( "page_id" => $p->ID ) ); ob_start(); isgal_render_open_graph(); echo substr_count( ob_get_clean(), "og:image" );' | tr -d '\r')"

head_ "Load more reveals what is already in the page"

LMHTML="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "pageSize" => 2, "limit" => 12 ) );' | tr -d '\r')"
assert "every figure is in the page" "6" "$(grep -c '<figure class="isgal-item"' <<<"$LMHTML")"
assert "all past the first batch carry hidden from the server" "4" "$(grep -c '<figure class="isgal-item"[^>]* hidden' <<<"$LMHTML")"
assert "each figure watches the store, with no filters configured" "6" "$(grep -c 'data-wp-watch--hidden="callbacks.syncItem"' <<<"$LMHTML")"
assert "the footer says how many are showing and offers the button" "1 1" "$(echo "$(tr -d '\n\t' <<<"$LMHTML" | grep -o 'Showing *<span[^>]*>2</span> of <span[^>]*>6</span>' | wc -l) $(grep -c 'class="isgal-more__button" data-wp-on--click="actions.more"' <<<"$LMHTML")")"
assert "the context carries the batch and the count shown" "1" "$(grep -o 'data-wp-context="[^"]*"' <<<"$LMHTML" | head -1 | grep -c '&quot;shown&quot;:2,&quot;batch&quot;:2,&quot;scroll&quot;:false')"
assert "JSON-LD still describes every image" "6" "$(grep -o '<script type="application/ld+json">.*</script>' <<<"$LMHTML" | grep -o '"contentUrl"' | wc -l)"
LMSCROLL="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "pageSize" => 4, "loadMore" => "scroll", "limit" => 12 ) );' | tr -d '\r')"
assert "the scroll option arms the observer on the footer" "1" "$(grep -c 'class="isgal-more" data-wp-init--scroll="callbacks.watchMore"' <<<"$LMSCROLL")"
LMOFF="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "pageSize" => 12, "limit" => 12 ) ); echo "|"; echo isgal_render_gallery( array( "gallery" => "mmgallery01", "pageSize" => 2, "layout" => "slideshow", "limit" => 12 ) );' | tr -d '\r')"
assert "a batch the gallery fits in, and a slideshow, get no footer" "0" "$(grep -c 'isgal-more' <<<"$LMOFF")"
assert "and a fitting batch adds no interactivity" "0" "$(cut -d'|' -f1 <<<"$(tr -d '\n' <<<"$LMOFF")" | grep -c 'data-wp-interactive')"
if ! wp post list --post_type=page --name=more --format=count | grep -q '^1$'; then
    wp post create --post_type=page --post_status=publish --post_title='More' --post_name=more \
        --post_content='<!-- wp:imagesnippets/gallery {"gallery":"mmgallery01","pageSize":3,"onClick":"lightbox","limit":12} /-->' >/dev/null
fi
MPAGE="$(fetch /more/)"
assert "the anonymous visitor gets three showing, the footer and the view module" "3 1 1" "$(echo "$(grep -c '<figure class="isgal-item"[^>]* hidden' <<<"$MPAGE") $(grep -c 'class="isgal-more"[^>]*>' <<<"$MPAGE") $(grep -c 'build/view.js' <<<"$MPAGE")")"

head_ "Site Health knows the numbers"

ADMIN_PHP='require_once WP_PLUGIN_DIR . "/image-snippets-gallery/includes/admin.php";'
assert "the check states the mirrored count and the no-request claim" "1" "$(wp eval "${ADMIN_PHP}"' $r = isgal_site_health_check(); echo (int) ( false !== strpos( $r["description"], "make no request to ImageSnippets" ) && preg_match( "/[1-9][0-9]* images? across/", $r["description"] ) );' | tr -d '\r')"
assert "the Info section reports zero requests per page view" "1" "$(wp eval "${ADMIN_PHP}"' $i = isgal_site_health_info( array() ); echo (int) ( 0 === strpos( $i["image-snippets-gallery"]["fields"]["requests"]["value"], "0" ) && (int) $i["image-snippets-gallery"]["fields"]["mirrored"]["value"] > 0 );' | tr -d '\r')"

head_ "JSON-LD on the public page"

tally "$( fetch | python3 ./check-jsonld.py )"

head_ "Payload profiles"

PROFILES="$( wp eval '
$sizes = array();
foreach ( array( "schema", "provenance", "full" ) as $p ) {
    $a    = isgal_resolve_attributes( array( "gallery" => "mmgallery01", "limit" => 12, "jsonldProfile" => $p ) );
    $rows = isgal_gallery_rows( $a, isgal_resolve_endpoint( $a ) );
    if ( is_wp_error( $rows ) ) { printf( "  FAIL  %s: %s\n", $p, $rows->get_error_message() ); continue; }
    $ld = isgal_jsonld( $rows, "mmgallery01", false, $p, "https://example.com/g/" );
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
# isgal_schedule_sync itself, so it is direct evidence rather than a side effect.
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

head_ "Shuffle, alt text source"

SHUF1="$(wp eval 'echo implode( ",", array_map( function ( $r ) { return $r["_post_id"]; }, isgal_mirror_query_rows( isgal_gallery_term( isgal_default_endpoint(), "mmgallery01" ), array_merge( isgal_resolve_attributes( array() ), array( "gallery" => "mmgallery01", "orderBy" => "random", "limit" => 12 ) ) ) ) );' | tr -d '\r')"
SHUF2="$(wp eval 'echo implode( ",", array_map( function ( $r ) { return $r["_post_id"]; }, isgal_mirror_query_rows( isgal_gallery_term( isgal_default_endpoint(), "mmgallery01" ), array_merge( isgal_resolve_attributes( array() ), array( "gallery" => "mmgallery01", "orderBy" => "random", "limit" => 12 ) ) ) ) );' | tr -d '\r')"
DATED="$(wp eval 'echo implode( ",", array_map( function ( $r ) { return $r["_post_id"]; }, isgal_mirror_query_rows( isgal_gallery_term( isgal_default_endpoint(), "mmgallery01" ), array_merge( isgal_resolve_attributes( array() ), array( "gallery" => "mmgallery01", "limit" => 12 ) ) ) ) );' | tr -d '\r')"
assert "shuffle keeps every image" "$(tr ',' '\n' <<<"$DATED" | sort | tr '\n' ',')" "$(tr ',' '\n' <<<"$SHUF1" | sort | tr '\n' ',')"
assert "shuffle is stable within a refetch window" "$SHUF1" "$SHUF2"
assert "shuffle is not the date order" "1" "$([ "$SHUF1" != "$DATED" ] && echo 1 || echo 0)"
assert "a different window gives a different order" "1" \
    "$(wp eval 'echo isgal_shuffle_rows( range( 1, 12 ), 5 ) === isgal_shuffle_rows( range( 1, 12 ), 6 ) ? 0 : 1;' | tr -d '\r')"

ALTG="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "limit" => 12, "displayCaption" => true ) );' | tr -d '\r')"
ALTT="$(wp eval 'echo isgal_render_gallery( array( "gallery" => "mmgallery01", "limit" => 12, "displayCaption" => true, "altSource" => "title" ) );' | tr -d '\r')"
FIRST_TITLE="$(grep -o 'isgal-cap-title[^>]*>[^<]*' <<<"$ALTT" | head -1 | sed 's/.*>//')"
assert "alt from title matches the caption" "$FIRST_TITLE" "$(grep -o 'alt="[^"]*"' <<<"$ALTT" | head -1 | sed 's/alt="//; s/"$//' | sed 's/&#039;/'"'"'/g; s/&amp;/\&/g')"
assert "default alt differs from the title for at least one image" "1" \
    "$([ "$(grep -o 'alt="[^"]*"' <<<"$ALTG")" != "$(grep -o 'alt="[^"]*"' <<<"$ALTT")" ] && echo 1 || echo 0)"

head_ "Onboarding: patterns, preview, demo page"

assert "four patterns registered under imagesnippets/" "4" \
    "$(wp eval 'echo count( array_filter( WP_Block_Patterns_Registry::get_instance()->get_all_registered(), function ( $p ) { return 0 === strpos( $p["name"], "imagesnippets/" ); } ) );' | tr -d '\r')"
assert "pattern category exists" "1" \
    "$(wp eval 'echo WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( "imagesnippets" ) ? 1 : 0;' | tr -d '\r')"
assert "every pattern parses to one gallery block" "4" \
    "$(wp eval 'foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) { if ( 0 !== strpos( $p["name"], "imagesnippets/" ) ) continue; $b = array_values( array_filter( parse_blocks( $p["content"] ), function ( $x ) { return null !== $x["blockName"]; } ) ); if ( 1 === count( $b ) && "imagesnippets/gallery" === $b[0]["blockName"] ) echo "."; }' | tr -d '\r' | tr -cd '.' | wc -c | tr -d ' ')"
assert "the slideshow pattern renders as a slideshow" "1" \
    "$(wp eval '$p = WP_Block_Patterns_Registry::get_instance()->get_registered( "imagesnippets/slideshow-hero" ); $b = parse_blocks( $p["content"] ); $b = array_values( array_filter( $b, function ( $x ) { return null !== $x["blockName"]; } ) ); echo isgal_render_gallery( array_merge( $b[0]["attrs"], array( "gallery" => "mmgallery01", "limit" => 12 ) ) );' | grep -c 'isgal-layout-slideshow' | awk '{print ($1>0)?1:0}')"
assert "inserter example is the static preview" "1" \
    "$(wp eval '$t = WP_Block_Type_Registry::get_instance()->get_registered( "imagesnippets/gallery" ); echo ! empty( $t->example["attributes"]["isPreview"] ) && empty( $t->example["attributes"]["gallery"] ) ? 1 : 0;' | tr -d '\r')"

DEMO_ID="$(wp eval 'echo isgal_create_demo_page( "mmgallery01", "portfolio-grid" );' | tr -d '\r')"
assert "demo page is a draft page" "page/draft" \
    "$(wp post get "$DEMO_ID" --field=post_type | tr -d '\r')/$(wp post get "$DEMO_ID" --field=post_status | tr -d '\r')"
assert "demo page holds the gallery with the pattern's settings" "1" \
    "$(wp post get "$DEMO_ID" --field=post_content | grep -c '"gallery":"mmgallery01".*"columns":4' | awk '{print ($1>0)?1:0}')"
assert "demo page renders the gallery" "$TRUE_COUNT" \
    "$(wp eval '$b = parse_blocks( get_post( '"$DEMO_ID"' )->post_content ); echo isgal_render_gallery( array_merge( $b[0]["attrs"], array( "limit" => 12 ) ) );' | grep -c '<figure class="isgal-item"')"
assert "an empty gallery name is refused" "1" \
    "$(wp eval 'echo is_wp_error( isgal_create_demo_page( "" ) ) ? 1 : 0;' | tr -d '\r')"
wp post delete "$DEMO_ID" --force >/dev/null

head_ "A gallery can be made of something other than a dataset"

# The seam only: a term carrying its own source is asked for its pattern, and
# everything after the list query is the same sync. No UI writes one yet.
SRC="$(wp eval '
$ep = isgal_default_endpoint();
$t  = isgal_gallery_term( $ep, "verify-pelicans", true );
update_term_meta( $t->term_id, ISGAL_TERM_SOURCE, wp_json_encode( array( "kind" => "verify" ) ) );
add_filter( "isgal_sync_max_images", function () { return 4; } );
add_filter( "isgal_source_fragment", function ( $f, $source ) { return "verify" === $source["kind"] ? "?image lio:depicts <http://dbpedia.org/resource/Brown_Pelican>." : $f; }, 10, 2 );
$q = isgal_build_sparql_list( isgal_source_fragment( $ep, "verify-pelicans" ) );
$r = isgal_sync_gallery( $ep, "verify-pelicans", array( "timeout" => 30 ) );
$n = count( get_objects_in_term( $t->term_id, ISGAL_TAXONOMY ) );
$d = isgal_build_sparql_list( isgal_source_fragment( $ep, "mmgallery01" ) );
isgal_prune_mirror();
$kept = isgal_gallery_term( $ep, "verify-pelicans" );
$left = $kept ? count( get_objects_in_term( $kept->term_id, ISGAL_TAXONOMY ) ) : -1;
if ( $kept ) { wp_delete_term( $kept->term_id, ISGAL_TAXONOMY ); }
echo ( false !== strpos( $q, "lio:depicts" ) && false === strpos( $q, "lio:isIn" ) ? 1 : 0 ), " ", ( is_wp_error( $r ) ? $r->get_error_message() : $n ), " ", ( false !== strpos( $d, "lio:isIn <https://imagesnippets.com/imgtag/datasets/Imagesnippets/mmgallery01>" ) ? 1 : 0 ), " ", ( $kept ? 1 : 0 ), " ", $left;
' | tr -d '\r')"
assert "its own pattern replaces the dataset one; four images mirrored; a plain gallery still asks for its dataset; pruning keeps the definition and drops its images" "1 4 1 1 0" "$SRC"
assert "the real gallery is untouched" "6" "$(fixture status gallery | awk '/^mirrored/{print $2}')"

head_ "Saved sources"

wp isgal source rm '~verify-pelicans' >/dev/null 2>&1 || true
assert "a pattern reaching for another server is refused" "isgal_source_keyword" "$(wp eval '$r = isgal_validate_source_pattern( "?image lio:depicts dbr:Brown_Pelican. } SERVICE <http://example.org/> { ?a ?b ?c" ); echo is_wp_error( $r ) ? $r->get_error_code() : "ok";' | tr -d '\r')"
assert "a pattern that closes the plugin's braces is refused" "isgal_source_braces" "$(wp eval '$r = isgal_validate_source_pattern( "?image lio:depicts dbr:Brown_Pelican. } } { {" ); echo is_wp_error( $r ) ? $r->get_error_code() : "ok";' | tr -d '\r')"
assert "a pattern that never names ?image is refused" "isgal_source_no_image" "$(wp eval '$r = isgal_validate_source_pattern( "?x lio:depicts dbr:Brown_Pelican." ); echo is_wp_error( $r ) ? $r->get_error_code() : "ok";' | tr -d '\r')"
assert "a person's own words are not mistaken for keywords" "1" "$(wp eval 'echo true === isgal_validate_source_pattern( "?image dc:creator ?from; schema:copy \"DROP it\"." ) ? 1 : 0;')"
SADD="$(wp eval 'add_filter( "isgal_source_max_images", function () { return 5; } ); $n = isgal_save_source( "Verify pelicans", "?image lio:depicts dbr:Brown_Pelican.", "" ); $r = isgal_refresh_gallery( isgal_default_endpoint(), $n ); echo $n, " ", is_wp_error( $r ) ? $r->get_error_message() : $r["images"];' | tr -d '\r')"
assert "saving gives the name a block keeps, and fetches up to the ceiling" "~verify-pelicans 5" "$SADD"
assert "a second source of the same name is refused" "isgal_source_exists" "$(wp eval '$r = isgal_save_source( "Verify pelicans", "?image lio:depicts dbr:Osprey.", "" ); echo is_wp_error( $r ) ? $r->get_error_code() : "saved";' | tr -d '\r')"
if ! wp post list --post_type=page --name=verify-source --format=count | grep -q '^1$'; then
    wp post create --post_type=page --post_status=publish --post_title='Verify source' --post_name=verify-source \
        --post_content='<!-- wp:imagesnippets/gallery {"gallery":"~verify-pelicans","displayTitle":true} /-->' >/dev/null
fi
VSRC="$(fetch /verify-source/)"
assert "the visitor sees its images under its label, with a share image" "5 1 1" "$(echo "$(grep -c '<figure class="isgal-item"' <<<"$VSRC") $(grep -c 'class="isgal-title">Verify pelicans<' <<<"$VSRC") $(grep -c '<meta property="og:image" content=' <<<"$VSRC")")"
assert "the picker offers it, first" "~verify-pelicans" "$(wp eval 'wp_set_current_user( 1 ); $r = rest_do_request( new WP_REST_Request( "GET", "/imagesnippets/v1/galleries" ) ); $d = $r->get_data(); echo $d["galleries"][0]["value"];' | tr -d '\r')"
SEDIT="$(wp eval '$t = isgal_gallery_term( isgal_default_endpoint(), "~verify-pelicans" ); $id = $t->term_id; isgal_save_source( "Verify pelicans", "?image lio:depicts dbr:Brown_Pelican. ?image dc:title ?t.", "~verify-pelicans" ); $u = isgal_gallery_term( isgal_default_endpoint(), "~verify-pelicans" ); echo ( $u->term_id === $id ? 1 : 0 ), " ", isgal_gallery_synced_at( $u );' | tr -d '\r')"
assert "rewriting the pattern keeps the gallery and marks it to be fetched again" "1 0" "$SEDIT"
wp post delete "$(wp post list --post_type=page --name=verify-source --field=ID)" --force >/dev/null
assert "deleting it removes the term and the images only it held" "1 0" "$(wp isgal source rm '~verify-pelicans' | grep -c Deleted) $(wp eval 'echo isgal_gallery_term( isgal_default_endpoint(), "~verify-pelicans" ) ? 1 : 0;')"
assert "the real gallery is still untouched" "6" "$(fixture status gallery | awk '/^mirrored/{print $2}')"

head_ "WP-CLI"

assert "wp isgal status lists the gallery" "mmgallery01" "$(wp isgal status --format=csv | awk -F, 'NR==2{print $1}' | tr -d '\r')"
assert "wp isgal sync reports counts" "1" "$(wp isgal sync mmgallery01 | grep -c 'images (+' )"

head_ "Sync status"
wp isgal status | sed 's/^/       /'

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
