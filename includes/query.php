<?php
/**
 * Server-side SPARQL query, caching, and HTML/JSON-LD rendering.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a value destined for interpolation inside a SPARQL IRI.
 * Allow word chars plus @ . - only.
 *
 * @param string $value Raw value.
 * @return string Sanitized value.
 */
function isg_sanitize_iri_segment( $value ) {
	return preg_replace( '/[^\w@.\-]/', '', (string) $value );
}

/**
 * Turn an image identifier / filename into a human-ish label.
 * e.g. "Trevor%20Welding-20120225-00087.jpg" -> "Trevor Welding".
 *
 * @param string $url Image IRI or contentUrl.
 * @return string
 */
function isg_humanize_filename( $url ) {
	$base = rawurldecode( basename( (string) $url ) );
	$base = preg_replace( '/\.[a-z0-9]{2,4}$/i', '', $base ); // strip extension
	$base = preg_replace( '/[ _-]+\d{6,}.*$/', '', $base );    // strip trailing date/id runs
	$base = trim( str_replace( array( '_', '-' ), ' ', $base ) );
	return $base;
}

/**
 * First non-empty string from a list.
 *
 * @param array $candidates Ordered candidates.
 * @return string
 */
function isg_first( array $candidates ) {
	foreach ( $candidates as $c ) {
		if ( '' !== trim( (string) $c ) ) {
			return (string) $c;
		}
	}
	return '';
}

/**
 * Decompose a Flickr static-photo URL into base + extension, with any existing
 * size suffix stripped — or null when it isn't a Flickr photo URL. Flickr encodes
 * the rendition size in the filename suffix (`…_{secret}_{size}.jpg`), and the
 * secret is >=6 chars, so a trailing `_{single-known-letter}` is unambiguously a
 * size code (never the secret).
 *
 * @param string $url Image URL.
 * @return array{base:string,ext:string}|null
 */
function isg_flickr_base( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['host'] ) || ! preg_match( '/(^|\.)staticflickr\.com$/i', $parts['host'] ) ) {
		return null;
	}
	$path = isset( $parts['path'] ) ? $parts['path'] : '';
	if ( ! preg_match( '/\.\w+$/', $path, $em ) ) {
		return null;
	}
	$ext  = $em[0];
	$stem = substr( $path, 0, -strlen( $ext ) );
	$stem = preg_replace( '/_([sqtmnwzcbhko])$/i', '', $stem ); // drop existing size code
	if ( ! preg_match( '#/\d+_[0-9a-z]+$#i', $stem ) ) {
		return null;
	}
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
	return array( 'base' => $scheme . '://' . $parts['host'] . $stem, 'ext' => $ext );
}

/**
 * Return a Flickr URL at the requested size code ('' = 500px medium). Non-Flickr
 * URLs are returned unchanged (already full-resolution).
 *
 * @param string $url  Image URL.
 * @param string $code Flickr size code (e.g. n=320, z=640, c=800, b=1024).
 * @return string
 */
function isg_flickr_sized( $url, $code ) {
	$f = isg_flickr_base( $url );
	if ( null === $f ) {
		return $url;
	}
	return $f['base'] . ( '' !== $code ? '_' . $code : '' ) . $f['ext'];
}

/**
 * Build a srcset of real Flickr renditions with true pixel-width descriptors, or
 * '' for non-Flickr URLs. Replaces the old `thumb 500w, content 2000w` pair whose
 * "thumb" was actually the 128px IS thumbnail — the source of the gallery's blur.
 *
 * @param string $url Image URL (the full-res source / contentUrl).
 * @return string
 */
function isg_flickr_srcset( $url ) {
	if ( null === isg_flickr_base( $url ) ) {
		return '';
	}
	$widths = array(
		'n' => 320,
		'z' => 640,
		'c' => 800,
		'b' => 1024,
	);
	$out = array();
	foreach ( $widths as $code => $w ) {
		$out[] = esc_url( isg_flickr_sized( $url, $code ) ) . ' ' . $w . 'w';
	}
	return implode( ', ', $out );
}

/**
 * Whether the current request is a block-editor preview.
 *
 * The editor previews dynamic blocks through the block-renderer REST route, so
 * REST_REQUEST alone would also match public REST consumers. The capability
 * check narrows it to someone actually editing.
 *
 * @return bool
 */
function isg_is_editor_preview() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
}

/**
 * Transient key for a query result. The context is part of the hash so the
 * editor's short-lived entry never masks the public one (and vice versa).
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @param string $context  'public' or 'editor'.
 * @return string
 */
function isg_cache_key( $endpoint, $query, $context = 'public' ) {
	// The isg2_ prefix marks the stale-while-revalidate payload shape. Entries
	// written by <=0.2.1 used isg_ and are simply never read; they self-expire.
	return 'isg2_' . md5( $context . '|' . $endpoint . '|' . $query );
}

/**
 * Lock key that keeps a burst of traffic from scheduling N background refreshes.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @return string
 */
function isg_lock_key( $endpoint, $query ) {
	return 'isg2lock_' . md5( $endpoint . '|' . $query );
}

/**
 * Resolve the cache lifetime, in seconds, for a set of resolved attributes.
 *
 * A cacheTtl of 0 disables caching entirely — the "always live" mode. Editor
 * previews are capped at a few seconds: ServerSideRender re-renders on every
 * attribute change, so a true bypass would fire one SPARQL query per tick of
 * the "Maximum images" slider.
 *
 * @param array $a Resolved attributes.
 * @return int Seconds.
 */
function isg_configured_ttl( array $a ) {
	$minutes = isset( $a['cacheTtl'] ) ? (int) $a['cacheTtl'] : 10;
	return max( 0, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;
}

/**
 * Resolve the cache lifetime for the current request, applying the editor cap.
 *
 * @param array $a Resolved attributes.
 * @return int Seconds.
 */
function isg_resolve_ttl( array $a ) {
	$ttl = isg_configured_ttl( $a );

	if ( isg_is_editor_preview() ) {
		$ttl = min( $ttl, ISG_EDITOR_TTL );
	}

	/**
	 * Filter the cache lifetime in seconds. Return 0 to disable caching.
	 *
	 * @param int   $ttl Resolved lifetime in seconds.
	 * @param array $a   Resolved block attributes.
	 */
	return max( 0, (int) apply_filters( 'isg_cache_ttl', $ttl, $a ) );
}

/**
 * Store rows with a soft expiry. The transient itself lives until soft + grace,
 * so a lapsed entry is still readable and can be served stale while a background
 * job refreshes it. Grace also keeps galleries on screen when the endpoint is down.
 *
 * @param string $key       Transient key.
 * @param array  $rows      Normalized rows.
 * @param int    $cache_ttl Soft lifetime in seconds.
 * @param int    $grace     Extra seconds the stale copy stays readable.
 * @return void
 */
function isg_store_rows( $key, array $rows, $cache_ttl, $grace ) {
	$grace = max( 0, (int) $grace );
	set_transient(
		$key,
		array(
			'rows' => $rows,
			'soft' => time() + (int) $cache_ttl,
		),
		(int) $cache_ttl + $grace
	);
}

/**
 * Fingerprint of a result set, for deciding whether anything actually changed.
 *
 * A refresh that returns identical rows must not purge page caches: on a busy
 * site that would throw away a perfectly good cache every few minutes.
 *
 * @param array $rows Normalized rows.
 * @return string
 */
function isg_rows_signature( array $rows ) {
	return md5( wp_json_encode( $rows ) );
}

/**
 * Queue a background refresh for an entry that is past its soft expiry.
 *
 * The lock stores the time it was taken, not a flag, so a later read can tell
 * whether the scheduled job ever ran. See isg_cron_stalled().
 *
 * @param string $endpoint  SPARQL endpoint URL.
 * @param string $query     SPARQL query.
 * @param int    $cache_ttl Soft lifetime in seconds.
 * @param string $gallery   Gallery name, for targeting the page-cache purge.
 * @return void
 */
function isg_schedule_refresh( $endpoint, $query, $cache_ttl, $gallery = '' ) {
	$lock = isg_lock_key( $endpoint, $query );
	if ( false !== get_transient( $lock ) ) {
		return;
	}
	// Held for 5 minutes and cleared on success. A failed refresh keeps the lock
	// until it expires, which rate-limits retries against a struggling endpoint.
	set_transient( $lock, time(), 5 * MINUTE_IN_SECONDS );

	wp_schedule_single_event(
		time(),
		'isg_refresh_cache',
		array( $endpoint, $query, (int) $cache_ttl, (string) $gallery )
	);

	if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
		spawn_cron();
	}
}

/**
 * Whether a scheduled refresh looks like it is never going to run.
 *
 * WP-Cron only fires on traffic, and plenty of hosts disable it outright. When
 * that happens the background refresh never lands, and serving stale rows would
 * let a page cache freeze them indefinitely. Detect it by age: the lock records
 * when the job was queued, so a lock older than the margin means nothing picked
 * it up.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @return bool
 */
function isg_cron_stalled( $endpoint, $query ) {
	$queued_at = get_transient( isg_lock_key( $endpoint, $query ) );
	if ( ! $queued_at ) {
		return false; // Nothing queued yet, so nothing has failed to run.
	}

	$margin = max( 30, (int) apply_filters( 'isg_cron_stall_margin', ISG_CRON_STALL_MARGIN ) );
	return ( time() - (int) $queued_at ) > $margin;
}

/**
 * Record the outcome of a sync so the admin screen and Site Health can report it.
 *
 * @param string $gallery Gallery name.
 * @param array  $fields  Fields to merge into that gallery's status.
 * @return void
 */
function isg_record_sync( $gallery, array $fields ) {
	$gallery = trim( (string) $gallery );
	if ( '' === $gallery ) {
		return;
	}

	$status = get_option( 'isg_sync_status', array() );
	if ( ! is_array( $status ) ) {
		$status = array();
	}

	$existing            = isset( $status[ $gallery ] ) && is_array( $status[ $gallery ] ) ? $status[ $gallery ] : array();
	$status[ $gallery ]  = array_merge( $existing, $fields );
	$status[ $gallery ]['last_attempt'] = time();

	update_option( 'isg_sync_status', $status, false );
}

/**
 * Recorded sync status, for one gallery or all of them.
 *
 * @param string $gallery Gallery name, or '' for the whole map.
 * @return array
 */
function isg_sync_status( $gallery = '' ) {
	$status = get_option( 'isg_sync_status', array() );
	if ( ! is_array( $status ) ) {
		$status = array();
	}
	if ( '' === $gallery ) {
		return $status;
	}
	return isset( $status[ $gallery ] ) && is_array( $status[ $gallery ] ) ? $status[ $gallery ] : array();
}

/**
 * Refetch, rewrite the cache entry, and invalidate page caches if the data moved.
 *
 * Ordering matters: fresh rows are stored *before* anything is purged. Purging
 * first would let the next visitor repopulate the page cache with the stale copy
 * we were trying to get rid of.
 *
 * @param string $endpoint  SPARQL endpoint URL.
 * @param string $query     SPARQL query.
 * @param int    $cache_ttl Soft lifetime in seconds.
 * @param string $gallery   Gallery name, for targeting the page-cache purge.
 * @return array|WP_Error   The fresh rows, or WP_Error.
 */
function isg_do_refresh_cache( $endpoint, $query, $cache_ttl, $gallery = '', $force_purge = false ) {
	$rows = isg_fetch_rows( $endpoint, $query );
	if ( is_wp_error( $rows ) ) {
		// Leave the lock in place; it expires and the retry happens then.
		isg_record_sync( $gallery, array( 'error' => $rows->get_error_message() ) );
		return $rows;
	}

	$key      = isg_cache_key( $endpoint, $query );
	$previous = get_transient( $key );
	$had_rows = is_array( $previous ) && isset( $previous['rows'] ) && is_array( $previous['rows'] );
	$changed  = ! $had_rows || isg_rows_signature( $previous['rows'] ) !== isg_rows_signature( $rows );

	// A zero TTL is "always live" — storing rows there would quietly reintroduce
	// the caching the setting exists to switch off.
	if ( $cache_ttl > 0 ) {
		$grace = max( 0, (int) apply_filters( 'isg_cache_grace', HOUR_IN_SECONDS ) );
		isg_store_rows( $key, $rows, (int) $cache_ttl, $grace );
	}
	delete_transient( isg_lock_key( $endpoint, $query ) );

	isg_record_sync(
		$gallery,
		array(
			'last_sync' => time(),
			'rows'      => count( $rows ),
			'error'     => '',
			'changed'   => $changed,
		)
	);

	// Scheduled refreshes purge only when something actually changed, so a busy
	// site is not flushing pages every interval for nothing.
	//
	// A person who clicked Refresh purges either way. Their question is "does the
	// public page match ImageSnippets now?", and the stored rows matching is not
	// the same as the cached HTML matching — an earlier purge may have failed, or
	// the page may have been cached from an older state. Reporting success while
	// leaving stale HTML in place is precisely the failure that made the galleries
	// look broken in the first place.
	if ( $changed || $force_purge ) {
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
	}

	return $rows;
}
add_action( 'isg_refresh_cache', 'isg_do_refresh_cache', 10, 4 );

/**
 * Refresh every gallery used anywhere on the site.
 *
 * Lives here rather than in the admin screen so cron, WP-CLI, and anything else
 * running outside wp-admin can reach it. Always a manual action, so it purges
 * whether or not the data moved.
 *
 * @return array Map of gallery name to fresh rows, or WP_Error per gallery.
 */
function isg_refresh_all_galleries() {
	$results = array();
	$done    = array();

	// Driven by the blocks in use, not by gallery names. Two blocks can show the
	// same gallery with different limits or sorting, which are different queries
	// and therefore different cache entries; refreshing a name would refresh
	// whichever entry the defaults happen to produce and leave the rest stale.
	foreach ( isg_indexed_gallery_blocks() as $block ) {
		$a        = isg_resolve_attributes( $block['attrs'] );
		$gallery  = $block['gallery'];
		$endpoint = isg_resolve_endpoint( $a );
		$query    = isg_build_sparql( $a );

		// Several posts may embed identical blocks; one refresh serves them all.
		$key = isg_cache_key( $endpoint, $query );
		if ( isset( $done[ $key ] ) ) {
			continue;
		}
		$done[ $key ] = true;

		$rows = isg_do_refresh_cache( $endpoint, $query, isg_configured_ttl( $a ), $gallery, true );

		// Keyed by gallery for display, but a gallery can appear more than once
		// with different settings, so later results must not silently replace
		// an error reported by an earlier one.
		if ( ! isset( $results[ $gallery ] ) || is_wp_error( $rows ) ) {
			$results[ $gallery ] = $rows;
		}
	}

	return $results;
}

/**
 * Drop the cached rows for a query, in both editor and public contexts, and
 * invalidate any page cache holding the pages that display it.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @param string $gallery  Gallery name, for targeting the page-cache purge.
 * @return void
 */
function isg_purge_cache( $endpoint, $query, $gallery = '' ) {
	delete_transient( isg_cache_key( $endpoint, $query, 'public' ) );
	delete_transient( isg_cache_key( $endpoint, $query, 'editor' ) );
	delete_transient( isg_lock_key( $endpoint, $query ) );

	if ( '' !== trim( (string) $gallery ) ) {
		isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
	}

	/**
	 * Fires after a gallery's cached rows are dropped.
	 *
	 * @param string $endpoint SPARQL endpoint URL.
	 * @param string $query    SPARQL query.
	 * @param string $gallery  Gallery name.
	 */
	do_action( 'isg_cache_purged', $endpoint, $query, $gallery );
}

/**
 * Run the SPARQL query, reading through the cache.
 *
 * @param string $endpoint  SPARQL endpoint URL.
 * @param string $query     SPARQL query.
 * @param int    $cache_ttl Soft lifetime in seconds. 0 disables caching.
 * @param string $context   'public' or 'editor'.
 * @param string $gallery   Gallery name, for targeting the page-cache purge.
 * @return array|WP_Error   Normalized rows, or WP_Error.
 */
function isg_run_query( $endpoint, $query, $cache_ttl = 600, $context = 'public', $gallery = '' ) {
	$cache_ttl = max( 0, (int) $cache_ttl );
	if ( 0 === $cache_ttl ) {
		return isg_fetch_rows( $endpoint, $query );
	}

	// Editor entries are short-lived and never served stale — freshness is the
	// whole point there — so they get no grace window.
	$grace = ( 'editor' === $context )
		? 0
		: max( 0, (int) apply_filters( 'isg_cache_grace', HOUR_IN_SECONDS ) );

	$key    = isg_cache_key( $endpoint, $query, $context );
	$cached = get_transient( $key );

	if ( is_array( $cached ) && isset( $cached['rows'], $cached['soft'] ) && is_array( $cached['rows'] ) ) {
		if ( time() < (int) $cached['soft'] ) {
			return $cached['rows'];
		}

		// Past soft expiry. Normally the queued job refreshes and purges, and
		// this reader gets the stale copy without waiting. But if cron is not
		// dispatching, nothing will ever refresh or purge, and a page cache
		// would freeze this response for its own full lifetime. Pay the fetch
		// here instead — the slow read is bounded, the frozen page is not.
		// Editor entries carry no grace window, so this branch only ever runs for
		// the public context; the check keeps the refresh writing to the key it
		// was read from.
		if ( 'public' === $context && isg_cron_stalled( $endpoint, $query ) ) {
			$fresh = isg_do_refresh_cache( $endpoint, $query, $cache_ttl, $gallery );
			if ( ! is_wp_error( $fresh ) ) {
				return $fresh;
			}
			return $cached['rows']; // Endpoint down: last known good beats an empty gallery.
		}

		isg_schedule_refresh( $endpoint, $query, $cache_ttl, $gallery );
		return $cached['rows'];
	}

	$rows = isg_fetch_rows( $endpoint, $query );
	if ( is_wp_error( $rows ) ) {
		isg_record_sync( $gallery, array( 'error' => $rows->get_error_message() ) );
		return $rows;
	}

	isg_store_rows( $key, $rows, $cache_ttl, $grace );
	isg_record_sync( $gallery, array( 'last_sync' => time(), 'rows' => count( $rows ), 'error' => '' ) );
	return $rows;
}

/**
 * Fetch and normalize rows from the SPARQL endpoint. No caching.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @return array|WP_Error  Normalized rows, or WP_Error.
 */
function isg_fetch_rows( $endpoint, $query ) {
	// add_query_arg does NOT url-encode values, so encode the query ourselves.
	$url = add_query_arg( 'query', rawurlencode( $query ), $endpoint );

	$response = wp_remote_get(
		$url,
		array(
			// Whole graphs are a much larger response than the old column list —
			// measured at ~1.4s for 40 images, and the block allows up to 200.
			// This only ever runs from cron or an explicit refresh, never in a
			// reader's request path, so a longer ceiling costs nobody anything.
			'timeout' => 20,
			'headers' => array( 'Accept' => 'application/sparql-results+json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'isg_http', sprintf( 'SPARQL endpoint returned HTTP %d', $code ) );
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! isset( $json['results']['bindings'] ) ) {
		return new WP_Error( 'isg_parse', 'Unexpected SPARQL response format.' );
	}

	return isg_parse_graph_bindings( $json['results']['bindings'] );
}

/**
 * Resolve the visible title for a row (title -> name -> optional filename).
 *
 * @param array $row          Row.
 * @param bool  $use_filename Whether to fall back to a humanized filename.
 * @return string
 */
function isg_row_title( array $row, $use_filename ) {
	$title = isg_first( array( $row['title'], $row['name'] ) );
	if ( '' === $title && $use_filename ) {
		$title = isg_humanize_filename( $row['image'] ? $row['image'] : $row['content'] );
	}
	return $title;
}

/**
 * Resolve alt text (AltText -> extended description -> title).
 *
 * @param array $row Row.
 * @return string
 */
function isg_row_alt( array $row ) {
	return isg_first( array( $row['alt'], $row['desc'], $row['title'], $row['name'] ) );
}

/**
 * Editor-only data-quality notice. Rendered only inside the block-editor
 * preview (a REST request), never on the public front end.
 *
 * @param array $rows Rows.
 * @return string
 */
function isg_editor_notice( array $rows ) {
	if ( ! isg_is_editor_preview() || empty( $rows ) ) {
		return '';
	}

	$no_title = 0;
	$no_alt   = 0;
	foreach ( $rows as $row ) {
		if ( '' === isg_first( array( $row['title'], $row['name'] ) ) ) {
			++$no_title;
		}
		if ( '' === isg_first( array( $row['alt'], $row['desc'] ) ) ) {
			++$no_alt;
		}
	}

	if ( 0 === $no_title && 0 === $no_alt ) {
		return '';
	}

	$total = count( $rows );
	$msg   = sprintf(
		/* translators: 1: missing-title count, 2: missing-alt count, 3: total images */
		__( '%1$d of %3$d images have no title and %2$d have no alt text. Add them in ImageSnippets to improve accessibility and search visibility. (This notice is editor-only.)', 'image-snippets-gallery' ),
		$no_title,
		$no_alt,
		$total
	);

	return '<p class="isg-editor-notice" style="padding:.5em .75em;border:1px solid #f0b849;background:#fcf9e8;border-radius:4px;font-size:.85em">⚠ '
		. esc_html( $msg ) . '</p>';
}

/**
 * Attribute defaults. Must mirror block.json.
 *
 * @return array
 */
function isg_defaults() {
	return array(
		'gallery'        => '',
		'userId'         => '',
		'endpoint'       => '',
		'displayCaption' => false,
		'displayTitle'   => false,
		'layout'         => 'grid',
		'order'          => 'desc',
		'orderBy'        => 'date',
		'limit'          => 40,
		'thumbSize'      => 'medium',
		'aspectRatio'    => '4-3',
		'useFilename'    => false,
		'cacheTtl'       => 10,
		'jsonldProfile'  => 'provenance',
	);
}

/**
 * Fill in attribute defaults. Shared by the renderer and the refresh route so
 * both derive the same SPARQL query, and therefore the same cache key.
 *
 * @param array $attributes Raw block attributes.
 * @return array
 */
function isg_resolve_attributes( array $attributes ) {
	return wp_parse_args( $attributes, isg_defaults() );
}

/**
 * Resolve the SPARQL endpoint for a set of resolved attributes.
 *
 * @param array $a Resolved attributes.
 * @return string
 */
function isg_resolve_endpoint( array $a ) {
	return $a['endpoint'] ? esc_url_raw( $a['endpoint'] ) : ISG_DEFAULT_ENDPOINT;
}

/**
 * Render the gallery HTML for a set of block attributes. Called from render.php.
 *
 * @param array $attributes Block attributes.
 * @return string HTML.
 */
function isg_render_gallery( array $attributes ) {
	$a = isg_resolve_attributes( $attributes );

	$endpoint = isg_resolve_endpoint( $a );

	$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'justified' ), true ) ? $a['layout'] : 'grid';
	$size   = in_array( $a['thumbSize'], array( 'small', 'medium', 'large' ), true ) ? $a['thumbSize'] : 'medium';
	$ratio  = in_array( $a['aspectRatio'], array( 'original', '1-1', '4-3', '3-2', '16-9' ), true ) ? $a['aspectRatio'] : '4-3';
	// Aspect-ratio cropping is incompatible with true masonry (variable heights).
	if ( 'masonry' === $layout ) {
		$ratio = 'original';
	}

	$classes            = 'isg-gallery isg-layout-' . $layout . ' isg-size-' . $size . ' isg-ratio-' . $ratio;
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => $classes ) );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return sprintf(
			'<div %s><p class="isg-message">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Enter an ImageSnippets gallery name in the block settings.', 'image-snippets-gallery' )
		);
	}

	$context = isg_is_editor_preview() ? 'editor' : 'public';
	$query   = isg_build_sparql( $a );
	$rows    = isg_run_query( $endpoint, $query, isg_resolve_ttl( $a ), $context, $a['gallery'] );

	if ( is_wp_error( $rows ) ) {
		return sprintf(
			'<div %s><p class="isg-message isg-error">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Unable to load gallery right now.', 'image-snippets-gallery' )
		);
	}

	// Cached rows are held in a canonical order so their fingerprint is stable;
	// the block's own ordering is a display concern, applied here.
	$rows = isg_sort_rows( $rows, $a );

	$use_filename = (bool) $a['useFilename'];
	$position     = 0;

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php echo isg_editor_notice( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( $a['displayTitle'] ) : ?>
			<p class="isg-title"><?php echo esc_html( $a['gallery'] ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="isg-message"><?php echo esc_html( sprintf( /* translators: %s: gallery name */ __( '%s — no images available.', 'image-snippets-gallery' ), $a['gallery'] ) ); ?></p>
		<?php else : ?>
			<div class="isg-grid">
				<?php
				foreach ( $rows as $row ) :
					++$position;
					$title = isg_row_title( $row, $use_filename );
					$alt   = isg_row_alt( $row );
					// Always give the link an accessible name, even when alt is empty.
					$label = isg_first(
						array(
							$alt,
							$title,
							sprintf( /* translators: 1: gallery name, 2: position */ __( '%1$s image %2$d', 'image-snippets-gallery' ), $a['gallery'], $position ),
						)
					);
					?>
					<figure class="isg-item" vocab="https://schema.org/" typeof="ImageObject">
						<a href="<?php echo esc_url( $row['page'] ? $row['page'] : '#' ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
							<?php
							// The source URL (contentUrl) is the full-res original; for Flickr it
							// carries the size in its filename suffix, so we request a rendition
							// matched to the column instead of the legacy 128px IS thumbnail (which
							// the old srcset mislabeled as 500w → blur). The thumbnail survives only
							// as an onerror fallback for link-rotted 2013-era source URLs.
							// Prefer the explicit contentUrl, else the image IRI (itself the full-res
							// source URL), and only fall back to the 128px thumbnail as a last resort.
							$isg_source = $row['content'];
							if ( '' === $isg_source && preg_match( '#^https?://#i', $row['image'] ) ) {
								$isg_source = $row['image'];
							}
							if ( '' === $isg_source ) {
								$isg_source = $row['thumb'];
							}
							$isg_src_map = array( 'small' => 'n', 'medium' => 'z', 'large' => 'c' );
							$isg_code    = isset( $isg_src_map[ $size ] ) ? $isg_src_map[ $size ] : 'z';
							$isg_src     = isg_flickr_sized( $isg_source, $isg_code );
							$isg_srcset  = isg_flickr_srcset( $isg_source );
							// Column min-widths from style.scss (small 120 / medium 200 / large 320),
							// with headroom since columns stretch to fill (auto-fill, 1fr).
							$isg_sizes_map = array( 'small' => '160px', 'medium' => '260px', 'large' => '420px' );
							$isg_sizes     = isset( $isg_sizes_map[ $size ] ) ? $isg_sizes_map[ $size ] : '260px';
							?>
							<img
								src="<?php echo esc_url( $isg_src ); ?>"
								<?php if ( '' !== $isg_srcset ) : ?>
								srcset="<?php echo $isg_srcset; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each URL escaped in isg_flickr_srcset() ?>"
								sizes="<?php echo esc_attr( $isg_sizes ); ?>"
								<?php endif; ?>
								<?php if ( $row['thumb'] && $row['thumb'] !== $isg_src ) : ?>
								onerror='this.onerror=null;this.removeAttribute("srcset");this.src="<?php echo esc_url( $row['thumb'] ); ?>";'
								<?php endif; ?>
								alt="<?php echo esc_attr( $alt ); ?>"
								loading="lazy"
								decoding="async"
								property="contentUrl"
							/>
						</a>
						<?php if ( '' !== $row['web'] ) : ?>
							<span property="license" hidden><?php echo esc_html( $row['web'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $row['licurl'] ) : ?>
							<span property="acquireLicensePage" hidden><?php echo esc_html( $row['licurl'] ); ?></span>
						<?php endif; ?>
						<?php if ( $a['displayCaption'] && '' !== $title ) : ?>
							<figcaption class="isg-caption" property="name"><?php echo esc_html( $title ); ?></figcaption>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php
			if ( '' !== $a['userId'] && '' !== $rows[0]['rights'] ) :
				?>
				<p class="isg-footer"><?php echo esc_html( sprintf( /* translators: %s: rights statement */ __( 'Images %s', 'image-snippets-gallery' ), $rows[0]['rights'] ) ); ?></p>
			<?php endif; ?>
			<?php echo isg_jsonld( $rows, $a['gallery'], $use_filename, $a['jsonldProfile'], isg_current_permalink() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
