#!/usr/bin/env bash
#
# Make the site's stored copy of a gallery disagree with ImageSnippets, so the
# next refresh has something real to correct.
#
# The honest test of the refresh button is "add an image on ImageSnippets, watch
# the site pick it up", but that needs a collection you can write to. This is
# the same test from the other end: drop an image from what the site has stored,
# so a refresh must notice the difference, fetch the missing one, and purge the
# page cache. The purge path being exercised is identical.
#
# Usage: ./devenv/simulate-change.sh [page-slug]      (default: gallery)

set -euo pipefail
cd "$(dirname "$0")"

SLUG="${1:-gallery}"

# `wp eval` takes a single positional argument, so the slug is interpolated into
# the snippet rather than passed alongside it.
docker compose run --rm -T cli wp --path=/var/www/html eval '
$slug  = "'"${SLUG}"'";
$posts = get_posts( array( "name" => $slug, "post_type" => "page", "numberposts" => 1 ) );
if ( empty( $posts ) ) { echo "No page with slug {$slug}\n"; exit( 1 ); }

// Read the block attributes off the page itself, so the cache key computed here
// is exactly the one the rendered page reads from.
$found = null;
foreach ( parse_blocks( $posts[0]->post_content ) as $block ) {
    if ( "imagesnippets/gallery" === $block["blockName"] ) { $found = $block["attrs"]; break; }
}
if ( null === $found ) { echo "No gallery block on that page\n"; exit( 1 ); }

$a        = isg_resolve_attributes( (array) $found );
$endpoint = isg_resolve_endpoint( $a );
$query    = isg_build_sparql( $a );
$key      = isg_cache_key( $endpoint, $query );

$entry = get_transient( $key );
if ( ! is_array( $entry ) || empty( $entry["rows"] ) ) {
    echo "Nothing stored yet — load the page once first.\n";
    exit( 1 );
}

$before  = count( $entry["rows"] );
$dropped = array_shift( $entry["rows"] );
set_transient( $key, $entry, HOUR_IN_SECONDS );

// Purge the page cache too, or the fixture does not take: a warm cache keeps
// serving the render from before this ran, so the smaller gallery never
// reaches a visitor and the test appears to have done nothing. What is being
// staged is a site whose stored data is out of date, not a site whose page
// cache is out of date — that is the part the refresh is supposed to fix.
$purged = isg_purge_page_cache( isg_posts_for_gallery( $a["gallery"] ) );

printf( "gallery      %s\n", $a["gallery"] );
printf( "stored rows  %d -> %d\n", $before, count( $entry["rows"] ) );
printf( "removed      %s\n", $dropped["title"] ?: $dropped["image"] );
printf( "page cache   purged via %s\n", $purged ? implode( ", ", $purged ) : "nothing matched" );
echo "\nThe site now believes this gallery has one fewer image than it does.\n";
echo "Reload the public page (private window) to see the smaller gallery,\n";
echo "then click Refresh from ImageSnippets and reload again.\n";
'
