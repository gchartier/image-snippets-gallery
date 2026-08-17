<?php
/**
 * Test fixtures for the dev environment. Run with `wp eval-file`.
 *
 *   wp eval-file fixture.php status gallery
 *   wp eval-file fixture.php drop   gallery    remove one mirrored image, purge
 *   wp eval-file fixture.php ghost  gallery    add a mirrored image ImageSnippets does not have
 *   wp eval-file fixture.php age    gallery    mark the mirror past its interval
 *   wp eval-file fixture.php stall  gallery    past its interval, and cron never ran
 *   wp eval-file fixture.php empty  gallery    sync against a pretend-empty gallery
 *   wp eval-file fixture.php reset             empty the whole mirror
 *
 * Everything here resolves the gallery from the block on the page itself, so
 * the fixtures act on exactly what the page renders.
 *
 * @package ImageSnippetsGallery
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$command = isset( $args[0] ) ? $args[0] : 'status';
$slug    = isset( $args[1] ) ? $args[1] : 'gallery';

if ( 'reset' === $command ) {
	printf( "deleted %d mirrored images\n", isg_mirror_drop_all() );
	return;
}

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
$ttl      = isg_configured_ttl( $a );
$lock     = isg_lock_key( $endpoint, $gallery );
$term     = isg_gallery_term( $endpoint, $gallery );
$mirrored = $term instanceof WP_Term ? array_map( 'intval', (array) get_objects_in_term( $term->term_id, ISG_TAXONOMY ) ) : array();
$synced   = isg_gallery_synced_at( $term );

$need_mirror = static function () use ( $term, $mirrored ) {
	if ( ! $term instanceof WP_Term || empty( $mirrored ) ) {
		WP_CLI::error( 'Nothing mirrored yet — load the page once first.' );
	}
};

switch ( $command ) {

	case 'status':
		printf( "gallery      %s\n", $gallery );
		printf( "mirrored     %s\n", $term instanceof WP_Term ? count( $mirrored ) : 'none' );
		if ( $synced ) {
			$age = time() - $synced;
			printf( "interval     %s (%ds old, interval %ds)\n", $age < $ttl ? 'fresh' : 'expired', $age, $ttl );
		} else {
			printf( "interval     never synced\n" );
		}
		printf( "sync lock    %s\n", get_transient( $lock ) ? 'held' : 'free' );
		break;

	case 'drop':
		$need_mirror();
		if ( count( $mirrored ) < 2 ) {
			WP_CLI::error( 'Need at least two mirrored images to drop one.' );
		}
		$victim = get_post( $mirrored[0] );
		wp_delete_post( $victim->ID, true );

		// Purge too, or the fixture does not take: a warm page cache keeps serving
		// the render from before this ran, so the smaller gallery never reaches a
		// visitor and the change appears to have done nothing. What is being staged
		// is a site whose stored data is out of date, not one whose page cache is.
		$purged = isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "gallery      %s\n", $gallery );
		printf( "mirrored     %d -> %d\n", count( $mirrored ), count( $mirrored ) - 1 );
		printf( "removed      %s\n", $victim->post_title );
		printf( "page cache   purged via %s\n", $purged ? implode( ', ', $purged ) : 'nothing matched' );
		break;

	case 'ghost':
		$need_mirror();
		$id = wp_insert_post(
			array(
				'post_type'   => ISG_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Ghost image (fixture)',
			)
		);
		update_post_meta( $id, ISG_META_PAGE, 'https://example.invalid/ghost/' . $id );
		update_post_meta( $id, ISG_META_IMAGE, 'https://example.invalid/ghost/' . $id . '.jpg' );
		update_post_meta( $id, ISG_META_DATE, '2099-01-01T00:00:00Z' );
		update_post_meta( $id, ISG_META_TITLE, 'Ghost' );
		update_post_meta( $id, ISG_META_ROW, wp_slash( wp_json_encode( array(
			'image'    => 'https://example.invalid/ghost/' . $id . '.jpg',
			'page'     => 'https://example.invalid/ghost/' . $id,
			'thumb'    => 'https://example.invalid/ghost.jpg',
			'content'  => 'https://example.invalid/ghost.jpg',
			'title'    => 'Ghost',
			'name'     => '',
			'desc'     => '',
			'alt'      => '',
			'date'     => '2099-01-01T00:00:00Z',
			'rights'   => '',
			'web'      => '',
			'licurl'   => '',
			'location' => '',
			'creator'  => '',
			'abouts'   => array(),
			'keywords' => array(),
			'triples'  => array(),
			'labels'   => array(),
		) ) ) );
		wp_set_object_terms( $id, array( (int) $term->term_id ), ISG_TAXONOMY, true );
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "ghost        post %d attached to %s; mirrored %d -> %d\n", $id, $gallery, count( $mirrored ), count( $mirrored ) + 1 );
		break;

	case 'age':
		$need_mirror();
		update_term_meta( $term->term_id, ISG_TERM_SYNCED, time() - $ttl - 1 );
		delete_transient( $lock );
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "aged: mirror is past its interval, no sync queued\n" );
		break;

	case 'stall':
		$need_mirror();
		update_term_meta( $term->term_id, ISG_TERM_SYNCED, time() - $ttl - 1 );

		// A lock older than the stall margin is what "a sync was queued and
		// nothing ever ran it" looks like from inside a request.
		$margin = max( 30, (int) apply_filters( 'isg_cron_stall_margin', ISG_CRON_STALL_MARGIN ) );
		set_transient( $lock, time() - $margin - 60, HOUR_IN_SECONDS );
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
		printf( "stalled: expired, with a queued sync that cron never picked up\n" );
		break;

	case 'empty':
		$need_mirror();
		add_filter( 'isg_sync_image_list', '__return_empty_array' );
		$result = isg_sync_gallery( $endpoint, $gallery );
		remove_filter( 'isg_sync_image_list', '__return_empty_array' );
		$after = count( (array) get_objects_in_term( $term->term_id, ISG_TAXONOMY ) );
		printf( "sync         %s\n", is_wp_error( $result ) ? 'refused: ' . $result->get_error_message() : 'accepted' );
		printf( "mirrored     %d -> %d\n", count( $mirrored ), $after );
		break;

	default:
		WP_CLI::error( "Unknown command '{$command}'." );
}
