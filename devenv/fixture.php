<?php
/**
 * Test fixtures for the dev environment. Run with `wp eval-file`.
 *
 *   wp eval-file fixture.php status gallery
 *   wp eval-file fixture.php drop   gallery    remove one image, purge
 *   wp eval-file fixture.php age    gallery    mark the stored copy expired
 *   wp eval-file fixture.php stall  gallery    expired, and cron never ran
 *
 * Everything here resolves the cache key from the gallery block on the page
 * itself. Rebuilding attributes from defaults would compute a different key
 * than the rendered page reads — which was a real bug in the plugin, not just
 * a hazard in principle, so the fixtures must not repeat it.
 *
 * @package ImageSnippetsGallery
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$command = isset( $args[0] ) ? $args[0] : 'status';
$slug    = isset( $args[1] ) ? $args[1] : 'gallery';

$posts = get_posts(
	array(
		'name'        => $slug,
		'post_type'   => 'page',
		'numberposts' => 1,
	)
);

if ( empty( $posts ) ) {
	WP_CLI::error( "No page with slug '{$slug}'." );
}

$attrs = null;
foreach ( isg_collect_gallery_blocks( parse_blocks( $posts[0]->post_content ) ) as $found ) {
	$attrs = $found;
	break;
}

if ( null === $attrs ) {
	WP_CLI::error( "No gallery block on '{$slug}'." );
}

$a        = isg_resolve_attributes( $attrs );
$gallery  = $a['gallery'];
$endpoint = isg_resolve_endpoint( $a );
$query    = isg_build_sparql( $a );
$key      = isg_cache_key( $endpoint, $query );
$lock     = isg_lock_key( $endpoint, $query );

$entry = get_transient( $key );
$rows  = ( is_array( $entry ) && isset( $entry['rows'] ) && is_array( $entry['rows'] ) ) ? $entry['rows'] : null;

switch ( $command ) {

	case 'status':
		printf( "gallery      %s\n", $gallery );
		printf( "stored rows  %s\n", null === $rows ? 'none' : count( $rows ) );
		if ( is_array( $entry ) && isset( $entry['soft'] ) ) {
			$in = (int) $entry['soft'] - time();
			printf( "soft expiry  %s (%+d s)\n", $in > 0 ? 'fresh' : 'expired', $in );
		}
		printf( "refresh lock %s\n", get_transient( $lock ) ? 'held' : 'free' );
		break;

	case 'drop':
		if ( null === $rows || count( $rows ) < 2 ) {
			WP_CLI::error( 'Nothing stored yet — load the page once first.' );
		}
		$before  = count( $rows );
		$dropped = array_shift( $entry['rows'] );
		set_transient( $key, $entry, HOUR_IN_SECONDS );

		// Purge too, or the fixture does not take: a warm page cache keeps serving
		// the render from before this ran, so the smaller gallery never reaches a
		// visitor and the change appears to have done nothing. What is being staged
		// is a site whose stored data is out of date, not one whose page cache is.
		$purged = isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );

		printf( "gallery      %s\n", $gallery );
		printf( "stored rows  %d -> %d\n", $before, count( $entry['rows'] ) );
		printf( "removed      %s\n", $dropped['title'] ? $dropped['title'] : $dropped['image'] );
		printf( "page cache   purged via %s\n", $purged ? implode( ', ', $purged ) : 'nothing matched' );
		break;

	case 'age':
		if ( null === $rows ) {
			WP_CLI::error( 'Nothing stored yet — load the page once first.' );
		}
		$entry['soft'] = time() - 1;
		set_transient( $key, $entry, HOUR_IN_SECONDS );
		delete_transient( $lock );
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "aged: stored copy is past its soft expiry, no refresh queued\n" );
		break;

	case 'stall':
		if ( null === $rows ) {
			WP_CLI::error( 'Nothing stored yet — load the page once first.' );
		}
		$entry['soft'] = time() - 1;
		set_transient( $key, $entry, HOUR_IN_SECONDS );

		// A lock older than the stall margin is what "a refresh was queued and
		// nothing ever ran it" looks like from inside a request.
		$margin = max( 30, (int) apply_filters( 'isg_cron_stall_margin', ISG_CRON_STALL_MARGIN ) );
		set_transient( $lock, time() - $margin - 60, HOUR_IN_SECONDS );
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "stalled: expired, with a queued refresh that cron never picked up\n" );
		break;

	default:
		WP_CLI::error( "Unknown command '{$command}'." );
}
