<?php
/**
 * Rendering, freshness, and the SPARQL transport.
 *
 * Reads come from the mirror (includes/mirror.php); this file decides when the
 * mirror is stale and how it gets refreshed, then renders rows into HTML and
 * JSON-LD.
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
function isgal_sanitize_iri_segment( $value ) {
	return preg_replace( '/[^\w@.\-]/', '', (string) $value );
}

/**
 * Turn an image identifier / filename into a human-ish label.
 * e.g. "Trevor%20Welding-20120225-00087.jpg" -> "Trevor Welding".
 *
 * @param string $url Image IRI or contentUrl.
 * @return string
 */
function isgal_humanize_filename( $url ) {
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
function isgal_first( array $candidates ) {
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
function isgal_flickr_base( $url ) {
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
	return array(
		'base' => $scheme . '://' . $parts['host'] . $stem,
		'ext'  => $ext,
	);
}

/**
 * Return a Flickr URL at the requested size code ('' = 500px medium). Non-Flickr
 * URLs are returned unchanged (already full-resolution).
 *
 * @param string $url  Image URL.
 * @param string $code Flickr size code (e.g. n=320, z=640, c=800, b=1024).
 * @return string
 */
function isgal_flickr_sized( $url, $code ) {
	$f = isgal_flickr_base( $url );
	if ( null === $f ) {
		return $url;
	}
	return $f['base'] . ( '' !== $code ? '_' . $code : '' ) . $f['ext'];
}

/**
 * The Flickr renditions a source URL can be asked for, smallest first, keyed by
 * the filename suffix that selects each one.
 *
 * @return int[] Suffix code => pixel width of the long edge.
 */
function isgal_flickr_widths() {
	return array(
		'n' => 320,
		'z' => 640,
		'c' => 800,
		'b' => 1024,
	);
}

/**
 * The smallest rendition that still covers a requested width.
 *
 * @param int $width Requested width in pixels; 0 means unconstrained.
 * @return string Suffix code, or '' for the original.
 */
function isgal_flickr_code_for_width( $width ) {
	$width = (int) $width;
	if ( $width < 1 ) {
		return '';
	}
	foreach ( isgal_flickr_widths() as $code => $rendition ) {
		if ( $width <= $rendition ) {
			return $code;
		}
	}
	return '';
}

/**
 * Build a srcset of real Flickr renditions with true pixel-width descriptors, or
 * '' for non-Flickr URLs. Replaces the old `thumb 500w, content 2000w` pair whose
 * "thumb" was actually the 128px IS thumbnail — the source of the gallery's blur.
 *
 * @param string $url Image URL (the full-res source / contentUrl).
 * @return string
 */
function isgal_flickr_srcset( $url ) {
	if ( null === isgal_flickr_base( $url ) ) {
		return '';
	}
	$out = array();
	foreach ( isgal_flickr_widths() as $code => $w ) {
		// esc_url_raw, not esc_url: this is escaped once where it is printed,
		// and entity-encoding it here would be encoded again on the way out.
		$out[] = esc_url_raw( isgal_flickr_sized( $url, $code ) ) . ' ' . $w . 'w';
	}
	return implode( ', ', $out );
}

/**
 * Note which REST route is being served, and whether it writes.
 *
 * REST_REQUEST is true for the editor's preview, for a page being saved and
 * for a headless site reading content, and the three want different things
 * from the mirror. Recorded from rest_pre_dispatch because the constant says
 * nothing about the route. Batch requests arrive as one POST to /batch/v1.
 *
 * @param mixed           $result  Passed through untouched.
 * @param WP_REST_Server  $server  Server.
 * @param WP_REST_Request $request Request about to be dispatched.
 * @return mixed
 */
function isgal_note_rest_request( $result, $server, $request ) {
	isgal_rest_request( $request );
	return $result;
}
add_filter( 'rest_pre_dispatch', 'isgal_note_rest_request', 10, 3 );

/**
 * The REST request being served, as far as the renderer cares.
 *
 * @param WP_REST_Request|null $request Set by isgal_note_rest_request().
 * @return array [ 'route' => string, 'write' => bool ]
 */
function isgal_rest_request( $request = null ) {
	static $current = array(
		'route' => '',
		'write' => false,
	);
	if ( $request instanceof WP_REST_Request ) {
		$current = array(
			'route' => (string) $request->get_route(),
			'write' => ! in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ),
		);
	}
	return $current;
}

/**
 * Whether the current request is a block-editor preview.
 *
 * The editor previews dynamic blocks through the block-renderer REST route.
 * REST_REQUEST alone would also match public REST consumers, and REST_REQUEST
 * plus the capability also matches saving a post, whose response carries the
 * rendered content. That one must not count: a preview asks for fresh data and
 * waits for it, and a save that waits on ImageSnippets outlives the host's
 * gateway timeout and surfaces as "The response is not a valid JSON response."
 *
 * @return bool
 */
function isgal_is_editor_preview() {
	if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	$rest = isgal_rest_request();
	return 0 === strpos( $rest['route'], '/wp/v2/block-renderer/' );
}

/**
 * Whether the current request is writing through REST (saving a post, an
 * autosave, a batch). Its response carries rendered content nobody looks at,
 * so the gallery must never go to the network on its account.
 *
 * @return bool
 */
function isgal_is_rest_write() {
	if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	$rest = isgal_rest_request();
	return $rest['write'];
}

/**
 * Lock key that keeps a burst of traffic from scheduling N background syncs of
 * the same gallery.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return string
 */
function isgal_lock_key( $endpoint, $gallery ) {
	return 'isgal_lock_' . md5( $endpoint . '|' . $gallery );
}

/**
 * Resolve the sync interval, in seconds, for a set of resolved attributes.
 *
 * A cacheTtl of 0 is the "always live" mode: the gallery is re-synced on every
 * render. Editor previews are capped at a few seconds: ServerSideRender
 * re-renders on every attribute change, so a true bypass would fire one sync
 * per tick of the "Maximum images" slider.
 *
 * @param array $a Resolved attributes.
 * @return int Seconds.
 */
function isgal_configured_ttl( array $a ) {
	// null / '' = no per-block override: use the site default from Tools.
	$minutes = ( isset( $a['cacheTtl'] ) && '' !== $a['cacheTtl'] ) ? (int) $a['cacheTtl'] : isgal_default_ttl_minutes();
	return max( 0, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;
}

/**
 * Resolve the sync interval for the current request, applying the editor cap.
 *
 * @param array $a Resolved attributes.
 * @return int Seconds.
 */
function isgal_resolve_ttl( array $a ) {
	$ttl = isgal_configured_ttl( $a );

	if ( isgal_is_editor_preview() ) {
		$ttl = min( $ttl, ISGAL_EDITOR_TTL );
	}

	/**
	 * Filter the sync interval in seconds. Return 0 to sync on every render.
	 *
	 * @param int   $ttl Resolved interval in seconds.
	 * @param array $a   Resolved block attributes.
	 */
	return max( 0, (int) apply_filters( 'isgal_cache_ttl', $ttl, $a ) );
}

/**
 * Queue a background sync for a gallery that is past its interval.
 *
 * The lock stores the time it was taken, not a flag, so a later read can tell
 * whether the scheduled job ever ran. See isgal_cron_stalled().
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return void
 */
function isgal_schedule_sync( $endpoint, $gallery ) {
	$lock = isgal_lock_key( $endpoint, $gallery );
	if ( false !== get_transient( $lock ) ) {
		return;
	}
	// Held for 5 minutes and cleared on success. A failed sync keeps the lock
	// until it expires, which rate-limits retries against a struggling endpoint.
	set_transient( $lock, time(), 5 * MINUTE_IN_SECONDS );

	wp_schedule_single_event( time(), 'isgal_sync_gallery', array( $endpoint, (string) $gallery ) );

	if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
		spawn_cron();
	}
}

/**
 * Whether a scheduled sync looks like it is never going to run.
 *
 * WP-Cron only fires on traffic, and plenty of hosts disable it outright. When
 * that happens the background sync never lands, and serving the stale mirror
 * would let a page cache freeze it indefinitely. Detect it by age: the lock
 * records when the job was queued, so a lock older than the margin means
 * nothing picked it up.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return bool
 */
function isgal_cron_stalled( $endpoint, $gallery ) {
	$queued_at = get_transient( isgal_lock_key( $endpoint, $gallery ) );
	if ( ! $queued_at ) {
		return false; // Nothing queued yet, so nothing has failed to run.
	}

	$margin = max( 30, (int) apply_filters( 'isgal_cron_stall_margin', ISGAL_CRON_STALL_MARGIN ) );
	return ( time() - (int) $queued_at ) > $margin;
}

/**
 * Record the outcome of a sync so the admin screen and Site Health can report it.
 *
 * @param string $gallery Gallery name.
 * @param array  $fields  Fields to merge into that gallery's status.
 * @return void
 */
function isgal_record_sync( $gallery, array $fields ) {
	$gallery = trim( (string) $gallery );
	if ( '' === $gallery ) {
		return;
	}

	$status = get_option( 'isgal_sync_status', array() );
	if ( ! is_array( $status ) ) {
		$status = array();
	}

	$existing                           = isset( $status[ $gallery ] ) && is_array( $status[ $gallery ] ) ? $status[ $gallery ] : array();
	$status[ $gallery ]                 = array_merge( $existing, $fields );
	$status[ $gallery ]['last_attempt'] = time();

	update_option( 'isgal_sync_status', $status, false );
}

/**
 * Recorded sync status, for one gallery or all of them.
 *
 * @param string $gallery Gallery name, or '' for the whole map.
 * @return array
 */
function isgal_sync_status( $gallery = '' ) {
	$status = get_option( 'isgal_sync_status', array() );
	if ( ! is_array( $status ) ) {
		$status = array();
	}
	if ( '' === $gallery ) {
		return $status;
	}
	return isset( $status[ $gallery ] ) && is_array( $status[ $gallery ] ) ? $status[ $gallery ] : array();
}

/**
 * A person asked for a refresh: sync now, accept whatever comes back, purge
 * either way.
 *
 * Their question is "does the public page match ImageSnippets now?", and the
 * mirror matching is not the same as the cached HTML matching — an earlier
 * purge may have failed, or the page may have been cached from an older state.
 * Reporting success while leaving stale HTML in place is precisely the failure
 * that made the galleries look broken in the first place.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return array|WP_Error Sync summary.
 */
function isgal_refresh_gallery( $endpoint, $gallery ) {
	delete_transient( isgal_lock_key( $endpoint, $gallery ) );
	return isgal_sync_gallery(
		$endpoint,
		$gallery,
		array(
			'force'   => true,
			'timeout' => 20,
		)
	);
}

/**
 * Refresh every gallery used anywhere on the site, and drop mirrored galleries
 * nothing uses any more.
 *
 * Lives here rather than in the admin screen so cron, WP-CLI, and anything else
 * running outside wp-admin can reach it. Always a manual action, so it purges
 * whether or not the data moved.
 *
 * @return array Map of gallery name to sync summary, or WP_Error per gallery.
 */
function isgal_refresh_all_galleries() {
	$results = array();
	$done    = array();

	// The sync unit is (endpoint, gallery). Two blocks showing the same gallery
	// with different limits or sorting read the same mirror, so one sync serves
	// them both.
	foreach ( isgal_indexed_gallery_blocks() as $block ) {
		$a        = isgal_resolve_attributes( $block['attrs'] );
		$gallery  = $block['gallery'];
		$endpoint = isgal_resolve_endpoint( $a );

		$key = $endpoint . '|' . $gallery;
		if ( isset( $done[ $key ] ) ) {
			continue;
		}
		$done[ $key ] = true;

		$result = isgal_refresh_gallery( $endpoint, $gallery );

		// Keyed by gallery for display; a gallery can appear on more than one
		// endpoint, so a later success must not silently replace an error.
		if ( ! isset( $results[ $gallery ] ) || is_wp_error( $result ) ) {
			$results[ $gallery ] = $result;
		}
	}

	isgal_prune_mirror();

	return $results;
}

/**
 * The rows for a block: read from the mirror, syncing first if it is missing
 * or stale enough that waiting is the right call.
 *
 * The rungs, in order:
 *   0. A REST write (saving the post) — never wait on the network. Queue a
 *      sync if one is due and serve whatever the mirror holds; nobody reads
 *      the content rendered into a save response, and a save that waits on a
 *      slow endpoint fails in the editor as an invalid JSON response.
 *   1. Never synced — sync now, inline. The alternative is an empty gallery
 *      frozen into a page cache.
 *   2. Interval of 0 ("always live") or an editor preview past its cap — sync
 *      now, inline. Both asked for freshness over speed.
 *   3. Past the interval and the queued sync never ran (cron off) — sync now,
 *      inline. The slow read is bounded; the frozen page is not.
 *   4. Past the interval — queue a sync, serve the mirror. The sync purges the
 *      page cache when it lands, if anything changed.
 *   5. Fresh — serve the mirror.
 *
 * @param array  $a        Resolved block attributes.
 * @param string $endpoint SPARQL endpoint URL.
 * @return array|WP_Error Rows, or WP_Error when nothing could be shown.
 */
function isgal_gallery_rows( array $a, $endpoint ) {
	$gallery = trim( (string) $a['gallery'] );
	$ttl     = isgal_resolve_ttl( $a );
	$term    = isgal_gallery_term( $endpoint, $gallery );
	$synced  = isgal_gallery_synced_at( $term );

	// Inline syncs run inside someone's page load. Shorter ceiling than cron.
	$inline = array( 'timeout' => (int) apply_filters( 'isgal_inline_sync_timeout', 10 ) );

	if ( isgal_is_rest_write() ) {
		if ( 0 === $synced || ( time() - $synced ) >= $ttl ) {
			isgal_schedule_sync( $endpoint, $gallery );
		}
	} elseif ( 0 === $synced ) {
		$result = isgal_sync_gallery( $endpoint, $gallery, $inline );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = isgal_gallery_term( $endpoint, $gallery );
	} elseif ( ( time() - $synced ) >= $ttl ) {
		if ( 0 === $ttl || isgal_is_editor_preview() || isgal_cron_stalled( $endpoint, $gallery ) ) {
			// Failure here is not fatal: the mirror still holds the last good copy.
			isgal_sync_gallery( $endpoint, $gallery, $inline );
		} else {
			isgal_schedule_sync( $endpoint, $gallery );
		}
	}

	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'isgal_no_mirror', __( 'Gallery has not been synchronised yet.', 'image-snippets-gallery' ) );
	}

	return isgal_mirror_query_rows( $term, $a );
}

/**
 * Run a SPARQL query and return its bindings. No caching, no interpretation.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @param int    $timeout  Seconds.
 * @return array|WP_Error  Bindings, or WP_Error.
 */
function isgal_sparql_json( $endpoint, $query, $timeout = 20 ) {
	// add_query_arg does NOT url-encode values, so encode the query ourselves.
	$url = add_query_arg( 'query', rawurlencode( $query ), $endpoint );

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => max( 1, (int) $timeout ),
			'headers' => array( 'Accept' => 'application/sparql-results+json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'isgal_http', sprintf( 'SPARQL endpoint returned HTTP %d', $code ) );
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! isset( $json['results']['bindings'] ) || ! is_array( $json['results']['bindings'] ) ) {
		return new WP_Error( 'isgal_parse', 'Unexpected SPARQL response format.' );
	}

	return $json['results']['bindings'];
}

/**
 * The fragment identifier for one image inside a rendered gallery.
 *
 * Search results have no page of their own: they link to a gallery page that
 * shows the image, which for a large gallery means landing at the top of a
 * grid of hundreds with no clue which one matched. The anchor is what lets
 * the browser scroll to it.
 *
 * Keyed on the named-graph IRI — the same identity the mirror posts use for
 * post_name — so the renderer and the mirror agree on the id without sharing
 * any state. Prefixed because a bare hash can begin with a digit, which is
 * not a valid CSS selector and would break the :target highlight.
 *
 * @param string $page Named-graph IRI of the image.
 * @return string Fragment id, or '' when the row carries no IRI.
 */
function isgal_image_anchor( $page ) {
	$page = (string) $page;
	return '' !== $page ? 'isgal-' . md5( $page ) : '';
}

/**
 * Resolve the visible title for a row (title -> name -> optional filename).
 *
 * @param array $row          Row.
 * @param bool  $use_filename Whether to fall back to a humanized filename.
 * @return string
 */
function isgal_row_title( array $row, $use_filename ) {
	$title = isgal_first( array( $row['title'], $row['name'] ) );
	if ( '' === $title && $use_filename ) {
		$title = isgal_humanize_filename( $row['image'] ? $row['image'] : $row['content'] );
	}
	return $title;
}

/**
 * The image's true pixel dimensions, or null when the graph does not say.
 *
 * ImageSnippets records these nowhere except the Open Graph tags it emits for
 * its own page, which the mirror keeps because it stores every graph whole. So
 * they are read back out of the triples rather than from a column.
 *
 * Only trusted when og:image names the very URL being rendered: the tag
 * describes whatever image that page advertises, and reporting one image's
 * dimensions for another would produce a confidently wrong aspect ratio —
 * worse than admitting we do not know, which callers can handle.
 *
 * @param array $row Row.
 * @return int[]|null array( width, height ), or null.
 */
function isgal_row_dimensions( array $row ) {
	$width  = 0;
	$height = 0;
	$og     = '';
	foreach ( $row['triples'] as $triple ) {
		$value = isset( $triple[2]['value'] ) ? (string) $triple[2]['value'] : '';
		switch ( $triple[1] ) {
			case 'https://ogp.me/ns#image':
				$og = $value;
				break;
			case 'https://ogp.me/ns#image:width':
				$width = (int) $value;
				break;
			case 'https://ogp.me/ns#image:height':
				$height = (int) $value;
				break;
		}
	}
	if ( $width < 1 || $height < 1 || '' === $og ) {
		return null;
	}
	$source = isgal_first( array( $row['content'], $row['image'] ) );
	// Compared decoded: the same URL reaches us percent-encoded in one triple
	// and not in another often enough to matter.
	if ( '' === $source || rawurldecode( $og ) !== rawurldecode( $source ) ) {
		return null;
	}
	return array( $width, $height );
}

/**
 * Resolve alt text. By default the image's own alt text on ImageSnippets
 * (og:image:alt, then the extended description) wins over the title, because
 * it is written to describe the picture; a block may prefer the title, which
 * is shorter and matches what a caption shows.
 *
 * @param array  $row    Row.
 * @param string $source 'graph' (default) or 'title'.
 * @return string
 */
function isgal_row_alt( array $row, $source = 'graph' ) {
	if ( 'title' === $source ) {
		return isgal_first( array( $row['title'], $row['name'], $row['alt'], $row['desc'] ) );
	}
	return isgal_first( array( $row['alt'], $row['desc'], $row['title'], $row['name'] ) );
}

/**
 * Editor-only data-quality notice. Rendered only inside the block-editor
 * preview (a REST request), never on the public front end.
 *
 * @param array $rows Rows.
 * @return string
 */
function isgal_editor_notice( array $rows ) {
	if ( ! isgal_is_editor_preview() || empty( $rows ) ) {
		return '';
	}

	$no_title = 0;
	$no_alt   = 0;
	foreach ( $rows as $row ) {
		if ( '' === isgal_first( array( $row['title'], $row['name'] ) ) ) {
			++$no_title;
		}
		if ( '' === isgal_first( array( $row['alt'], $row['desc'] ) ) ) {
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

	return '<p class="isgal-editor-notice" style="padding:.5em .75em;border:1px solid #f0b849;background:#fcf9e8;border-radius:4px;font-size:.85em">⚠ '
		. esc_html( $msg ) . '</p>';
}

/**
 * Attribute defaults. Must mirror block.json.
 *
 * @return array
 */
function isgal_defaults() {
	return array(
		'gallery'           => '',
		'userId'            => '',
		'endpoint'          => '',
		'displayCaption'    => false,
		'captionPosition'   => 'below',
		'captionFields'     => array( 'title' ),
		'hoverEffect'       => 'none',
		'captionBackground' => '',
		'onClick'           => 'page',
		'linkNewTab'        => true,
		'lightboxDetails'   => true,
		'lightboxFlip'      => true,
		'dateFields'        => null,
		'facets'            => array(),
		'facetMax'          => 12,
		'slideAutoplay'     => false,
		'slideInterval'     => 5,
		'slideNav'          => 'dots',
		'displayTitle'      => false,
		'titleLevel'        => 2,
		'layout'            => 'grid',
		'order'             => 'desc',
		'orderBy'           => 'date',
		'limit'             => 40,
		'pageSize'          => 0,
		'loadMore'          => 'button',
		'thumbSize'         => 'medium',
		'columns'           => 3,
		'aspectRatio'       => '4-3',
		'useFilename'       => false,
		'altSource'         => 'graph',
		'imageLoading'      => 'auto',
		'imageReveal'       => 'fade',
		'cacheTtl'          => null,
		'jsonldProfile'     => 'provenance',
		'imageBorder'       => null,
		'imageRadius'       => null,
		'imageShadow'       => '',
		'separateText'      => false,
		'titleColor'        => '',
		'titleSize'         => '',
		'captionColor'      => '',
		'captionSize'       => '',
	);
}

/**
 * The caption lines a block may show under (or over) each image, in the order
 * the editor offers them. Keys are what captionFields stores.
 *
 * @return array Key => label.
 */
function isgal_caption_fields() {
	return array(
		'title'   => __( 'Title', 'image-snippets-gallery' ),
		'creator' => __( 'Creator', 'image-snippets-gallery' ),
		'date'    => __( 'Date', 'image-snippets-gallery' ),
		'rights'  => __( 'Rights', 'image-snippets-gallery' ),
	);
}

/**
 * A date as stored on ImageSnippets, shown at the precision it was given:
 * "2002" stays a year, "2017-12" becomes a month, a full date follows the
 * site's date format.
 *
 * @param string $raw Date string from the graph.
 * @return string Empty when unparseable.
 */
function isgal_format_graph_date( $raw ) {
	$raw = preg_replace( '/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', trim( (string) $raw ) );
	if ( preg_match( '/^(\d{4})$/', $raw, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '/^(\d{4})-(\d{1,2})$/', $raw, $m ) ) {
		$ts = mktime( 12, 0, 0, (int) $m[2], 1, (int) $m[1] );
		return $ts ? date_i18n( 'F Y', $ts ) : $raw;
	}
	$ts = isgal_mirror_date_ts( $raw );
	if ( ! $ts ) {
		return '';
	}
	return date_i18n( get_option( 'date_format' ), $ts );
}

/**
 * The block's date priority, validated; null means the default.
 *
 * @param array $a Resolved attributes.
 * @return string[]|null
 */
function isgal_block_date_priority( array $a ) {
	if ( empty( $a['dateFields'] ) || ! is_array( $a['dateFields'] ) ) {
		return null;
	}
	$known = array_keys( isgal_date_sources() );
	$list  = array_values( array_intersect( $a['dateFields'], $known ) );
	return $list ? $list : null;
}

/**
 * The image's date for display: text plus which source it came from, so the
 * page can say so (a tooltip) instead of presenting a catalogue time as a
 * capture time.
 *
 * @param array $row Row.
 * @param array $a   Resolved attributes.
 * @return array [ 'text' => string, 'source' => string label ] or empty text.
 */
function isgal_row_display_date( array $row, array $a ) {
	$resolved = isgal_row_resolved_date( $row, isgal_block_date_priority( $a ) );
	if ( ! $resolved ) {
		return array(
			'text'   => '',
			'source' => '',
		);
	}
	$sources = isgal_date_sources();
	return array(
		'text'   => isgal_format_graph_date( $resolved['raw'] ),
		'source' => isset( $sources[ $resolved['source'] ] ) ? $sources[ $resolved['source'] ]['label'] : $resolved['source'],
	);
}

/**
 * The facets a gallery can be filtered by, in the order the editor offers
 * them. Keys are what the block's `facets` attribute stores and what the URL
 * carries (`?isgal_year=…`). Every one is read straight from the graph the
 * mirror already holds: a decade of hand annotation, not a taxonomy the site
 * owner has to maintain.
 *
 * @return array Key => label.
 */
function isgal_facets() {
	return array(
		'creator' => __( 'Creator', 'image-snippets-gallery' ),
		'year'    => __( 'Year', 'image-snippets-gallery' ),
		'camera'  => __( 'Camera', 'image-snippets-gallery' ),
		'rights'  => __( 'Rights', 'image-snippets-gallery' ),
	);
}

/**
 * The block's facets, validated and in its order; empty means no filter bar.
 *
 * @param array $a Resolved attributes.
 * @return string[]
 */
function isgal_block_facets( array $a ) {
	if ( empty( $a['facets'] ) || ! is_array( $a['facets'] ) ) {
		return array();
	}
	return array_values( array_unique( array_intersect( $a['facets'], array_keys( isgal_facets() ) ) ) );
}

/**
 * The camera an image was taken with, from EXIF Make and Model. Model usually
 * repeats the make ("Canon" / "Canon EOS 5D"), so it is not prefixed twice.
 *
 * @param array $row Row.
 * @return string Empty when the graph carries neither.
 */
function isgal_row_camera( array $row ) {
	$make  = '';
	$model = '';
	foreach ( (array) $row['triples'] as $triple ) {
		if ( 'http://ns.adobe.com/exif/1.0/Make' === $triple[1] && '' === $make ) {
			$make = trim( (string) $triple[2]['value'] );
		} elseif ( 'http://ns.adobe.com/exif/1.0/Model' === $triple[1] && '' === $model ) {
			$model = trim( (string) $triple[2]['value'] );
		}
	}
	if ( '' === $model ) {
		return $make;
	}
	if ( '' === $make || 0 === stripos( $model, $make ) ) {
		return $model;
	}
	return $make . ' ' . $model;
}

/**
 * One image's values for every facet, as shown. Matching is case-insensitive
 * (see isgal_facet_key()), so "Fog" and "fog" are one chip.
 *
 * @param array $row Row.
 * @param array $a   Resolved attributes (for the date priority).
 * @return array Facet key => string[] display values.
 */
function isgal_row_facet_values( array $row, array $a ) {
	$values = array(
		'creator' => array(),
		'year'    => array(),
		'camera'  => array(),
		'rights'  => array(),
	);
	if ( '' !== trim( (string) $row['creator'] ) ) {
		$values['creator'][] = trim( (string) $row['creator'] );
	}
	$date = isgal_row_resolved_date( $row, isgal_block_date_priority( $a ) );
	if ( $date && preg_match( '/^(\d{4})/', $date['raw'], $m ) ) {
		$values['year'][] = $m[1];
	}
	$camera = isgal_row_camera( $row );
	if ( '' !== $camera ) {
		$values['camera'][] = $camera;
	}
	if ( '' !== trim( (string) $row['rights'] ) ) {
		$values['rights'][] = trim( (string) $row['rights'] );
	}
	return $values;
}

/**
 * The form a facet value is matched and carried in the URL in.
 *
 * @param string $value Display value.
 * @return string
 */
function isgal_facet_key( $value ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $value ) ) : strtolower( trim( (string) $value ) );
}

/**
 * The filter bar's contents for a set of rows: per facet, the values that
 * occur, how often, most common first, cut to the block's limit. A facet
 * that could not narrow anything (one value, on every image) is left out —
 * a bar of chips that hide nothing is noise.
 *
 * @param array    $rows Rows.
 * @param array    $a    Resolved attributes.
 * @param string[] $keys Facets to build, in order.
 * @return array Facet key => [ 'label' => string, 'values' => [ [ 'key', 'label', count ], … ] ].
 */
function isgal_facet_index( array $rows, array $a, array $keys ) {
	$labels          = isgal_facets();
	$max             = max( 1, min( 100, (int) $a['facetMax'] ) );
	$counts          = array_fill_keys( $keys, array() );
	$shown           = array_fill_keys( $keys, array() );
	$rows_with_value = array_fill_keys( $keys, 0 );
	foreach ( $rows as $row ) {
		$values = isgal_row_facet_values( $row, $a );
		foreach ( $keys as $facet ) {
			$seen = array();
			foreach ( $values[ $facet ] as $value ) {
				$k = isgal_facet_key( $value );
				if ( '' === $k || isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				if ( ! isset( $counts[ $facet ][ $k ] ) ) {
					$counts[ $facet ][ $k ] = 0;
					$shown[ $facet ][ $k ]  = $value;
				}
				++$counts[ $facet ][ $k ];
			}
			if ( $seen ) {
				++$rows_with_value[ $facet ];
			}
		}
	}
	$index = array();
	foreach ( $keys as $facet ) {
		if ( ! $counts[ $facet ] ) {
			continue;
		}
		if ( 1 === count( $counts[ $facet ] ) && count( $rows ) === $rows_with_value[ $facet ] ) {
			continue; // Everything shares it: nothing to filter.
		}
		$c = $counts[ $facet ];
		$s = $shown[ $facet ];
		uksort(
			$c,
			static function ( $x, $y ) use ( $c, $s, $facet ) {
				if ( $c[ $x ] !== $c[ $y ] ) {
					return $c[ $y ] - $c[ $x ];
				}
				// Years read best in order; everything else alphabetically.
				return 'year' === $facet ? strcmp( $y, $x ) : strnatcasecmp( $s[ $x ], $s[ $y ] );
			}
		);
		$values = array();
		foreach ( array_slice( $c, 0, $max, true ) as $k => $n ) {
			$values[] = array( (string) $k, $s[ $k ], $n ); // PHP makes "2012" an int key; the URL and items carry strings.
		}
		$index[ $facet ] = array(
			'label'  => $labels[ $facet ],
			'values' => $values,
		);
	}
	return $index;
}

/**
 * The filter bar: a row of chips per facet, and a status line. The chips are
 * plain buttons that the view script wires up; without it (or in the editor)
 * they are inert and every image stays visible, so nothing is ever hidden
 * from a crawler or from a visitor without scripts.
 *
 * @param array $index isgal_facet_index() output.
 * @param int   $total Images in the gallery.
 * @return string HTML.
 */
function isgal_facet_bar_html( array $index, $total ) {
	ob_start();
	?>
	<div class="isgal-facets" role="group" aria-label="<?php esc_attr_e( 'Filter images', 'image-snippets-gallery' ); ?>">
		<?php foreach ( $index as $facet => $group ) : ?>
			<div class="isgal-facet isgal-facet-<?php echo esc_attr( $facet ); ?>">
				<span class="isgal-facet__name"><?php echo esc_html( $group['label'] ); ?></span>
				<span class="isgal-facet__chips">
					<?php foreach ( $group['values'] as $v ) : ?>
						<button type="button" class="isgal-chip" aria-pressed="false" data-wp-context="
						<?php
						echo esc_attr(
							wp_json_encode(
								array(
									'facet' => $facet,
									'value' => $v[0],
								)
							)
						);
						?>
																										" data-wp-on--click="actions.toggle" data-wp-bind--aria-pressed="state.chipOn" data-wp-class--is-active="state.chipOn"><?php echo esc_html( $v[1] ); ?> <span class="isgal-chip__count"><?php echo esc_html( number_format_i18n( $v[2] ) ); ?></span></button>
					<?php endforeach; ?>
				</span>
			</div>
		<?php endforeach; ?>
		<p class="isgal-facets__status" hidden data-wp-bind--hidden="!state.filtering">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: the number shown (live), 2: total images */
					__( 'Showing %1$s of %2$s images.', 'image-snippets-gallery' ),
					'<span data-wp-text="state.visibleCount">' . esc_html( number_format_i18n( $total ) ) . '</span>',
					esc_html( number_format_i18n( $total ) )
				),
				array( 'span' => array( 'data-wp-text' => array() ) )
			);
			?>
			<button type="button" class="isgal-facets__clear" data-wp-on--click="actions.clearFacets"><?php esc_html_e( 'Clear filters', 'image-snippets-gallery' ); ?></button>
		</p>
		<p class="isgal-facets__empty isgal-message" hidden data-wp-bind--hidden="state.visibleCount"><?php esc_html_e( 'No images match every selected filter.', 'image-snippets-gallery' ); ?></p>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Everything the lightbox needs for one image, resolved on the server so the
 * front end never fetches. Goes into the block's Interactivity context.
 *
 * @param array  $row          Row.
 * @param array  $a            Resolved attributes.
 * @param string $title        Resolved title.
 * @param string $alt          Resolved alt text.
 * @param string $source       Full-size source URL.
 * @return array
 */
function isgal_lightbox_item( array $row, array $a, $title, $alt, $source ) {
	$srcset = isgal_flickr_srcset( $source );
	$date   = isgal_row_display_date( $row, $a );
	return array(
		'anchor'     => isgal_image_anchor( $row['page'] ),
		'src'        => esc_url_raw( isgal_flickr_sized( $source, 'b' ) ),
		'srcset'     => $srcset,
		'alt'        => (string) $alt,
		'title'      => (string) $title,
		'creator'    => $a['lightboxDetails'] ? (string) $row['creator'] : '',
		'date'       => $a['lightboxDetails'] ? $date['text'] : '',
		'dateSource' => $a['lightboxDetails'] ? $date['source'] : '',
		'rights'     => $a['lightboxDetails'] ? (string) $row['rights'] : '',
		'page'       => $a['lightboxDetails'] ? esc_url_raw( $row['page'] ) : '',
	);
}

/**
 * The lightbox dialog for a gallery. One per block; the items live in the
 * wrapper's context, so this is only the frame that shows the current one.
 *
 * @param bool $flip Whether the image can be flipped to its metadata.
 * @return string HTML.
 */
function isgal_lightbox_html( $flip = false ) {
	ob_start();
	?>
	<dialog class="isgal-lightbox" data-wp-watch="callbacks.syncDialog" data-wp-class--is-flipped="context.flipped" data-wp-class--is-loading="context.loading" data-wp-on--close="actions.close" data-wp-on--click="actions.backdrop" data-wp-on--keydown="actions.keydown" data-wp-on--touchstart="actions.touchStart" data-wp-on--touchend="actions.touchEnd" aria-label="<?php esc_attr_e( 'Image viewer', 'image-snippets-gallery' ); ?>">
		<div class="isgal-lightbox__frame">
			<button type="button" class="isgal-lightbox__close" data-wp-on--click="actions.close" aria-label="<?php esc_attr_e( 'Close', 'image-snippets-gallery' ); ?>">&#x2715;</button>
			<button type="button" class="isgal-lightbox__nav isgal-lightbox__prev" data-wp-on--click="actions.prev" data-wp-bind--hidden="!state.hasMany" aria-label="<?php esc_attr_e( 'Previous image', 'image-snippets-gallery' ); ?>">&#x2039;</button>
			<figure class="isgal-lightbox__figure">
				<div class="isgal-flip">
					<div class="isgal-flip__inner">
						<div class="isgal-flip__front">
							<img class="isgal-lightbox__img" data-wp-watch="callbacks.watchImage" data-wp-on--load="actions.loaded" data-wp-on--error="actions.loaded"<?php echo $flip ? ' data-wp-on--click="actions.flip"' : ''; ?> data-wp-bind--src="state.current.src" data-wp-bind--srcset="state.current.srcset" data-wp-bind--alt="state.current.alt" sizes="100vw" decoding="async" />
							<span class="isgal-lightbox__spinner" aria-hidden="true"></span>
						</div>
						<?php if ( $flip ) : // The back of the photograph: the image's whole graph over a ghost of the image, as ImageSnippets shows it. ?>
						<div class="isgal-flip__back" data-wp-bind--aria-hidden="!context.flipped" data-wp-on--click="actions.flipBack">
							<img class="isgal-flip__ghost" data-wp-bind--src="state.current.src" alt="" aria-hidden="true" decoding="async" />
							<div class="isgal-flip__data" tabindex="0" role="group" aria-label="<?php esc_attr_e( 'Image metadata', 'image-snippets-gallery' ); ?>">
								<template data-wp-each--group="state.back">
									<section class="isgal-flip__group">
										<h3 class="isgal-flip__subject" data-wp-text="context.group.s"></h3>
										<dl>
											<template data-wp-each--row="context.group.rows">
												<div class="isgal-flip__row">
													<dt data-wp-text="context.row.p" data-wp-bind--title="context.row.i"></dt>
													<dd>
														<template data-wp-each--val="context.row.v">
															<span class="isgal-flip__value"><a data-wp-bind--hidden="!context.val.h" data-wp-bind--href="context.val.h" data-wp-text="context.val.t" target="_blank" rel="noopener"></a><span data-wp-bind--hidden="context.val.h" data-wp-text="context.val.t"></span></span>
														</template>
													</dd>
												</div>
											</template>
										</dl>
									</section>
								</template>
							</div>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<figcaption class="isgal-lightbox__caption">
					<?php if ( $flip ) : ?>
					<button type="button" class="isgal-lightbox__flip" data-wp-on--click="actions.flip" data-wp-bind--aria-pressed="context.flipped" aria-pressed="false"><span aria-hidden="true">&#x21C4;</span> <span data-wp-text="state.flipLabel"><?php esc_html_e( 'Flip to see the metadata', 'image-snippets-gallery' ); ?></span></button>
					<?php endif; ?>
					<span class="isgal-lightbox__title" data-wp-text="state.current.title"></span>
					<span class="isgal-lightbox__count" data-wp-text="state.position" data-wp-bind--hidden="!state.hasMany"></span>
					<dl class="isgal-lightbox__details" data-wp-bind--hidden="!state.hasDetails">
						<div data-wp-bind--hidden="!state.current.creator"><dt><?php esc_html_e( 'Creator', 'image-snippets-gallery' ); ?></dt><dd data-wp-text="state.current.creator"></dd></div>
						<div data-wp-bind--hidden="!state.current.date"><dt><?php esc_html_e( 'Date', 'image-snippets-gallery' ); ?></dt><dd><span data-wp-text="state.current.date"></span> <small class="isgal-lightbox__source" data-wp-text="state.current.dateSource"></small></dd></div>
						<div data-wp-bind--hidden="!state.current.rights"><dt><?php esc_html_e( 'Rights', 'image-snippets-gallery' ); ?></dt><dd data-wp-text="state.current.rights"></dd></div>
					</dl>
					<p class="isgal-lightbox__hosted" data-wp-bind--hidden="!state.current.page">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: the word "ImageSnippets", linked to the image's page there */
								__( 'Image metadata is hosted at %s', 'image-snippets-gallery' ),
								'<a data-wp-bind--href="state.current.page" target="_blank" rel="noopener">ImageSnippets</a>'
							),
							array(
								'a' => array(
									'data-wp-bind--href' => array(),
									'target'             => array(),
									'rel'                => array(),
								),
							)
						);
						?>
					</p>
				</figcaption>
			</figure>
			<button type="button" class="isgal-lightbox__nav isgal-lightbox__next" data-wp-on--click="actions.next" data-wp-bind--hidden="!state.hasMany" aria-label="<?php esc_attr_e( 'Next image', 'image-snippets-gallery' ); ?>">&#x203A;</button>
		</div>
	</dialog>
	<?php
	return ob_get_clean();
}

/**
 * The "Load more" footer: how many of the gallery's images are showing, and
 * the button that reveals the next batch. Every image is already in the page
 * (hidden past the first batch), so revealing is a state change, not a
 * request; with the scroll option the footer reveals itself when it comes
 * into view, and the button stays as the keyboard's way.
 *
 * @param int   $page_size Images shown at first, and per batch.
 * @param int   $total     Images in the gallery.
 * @param array $a         Resolved block attributes.
 * @return string HTML.
 */
function isgal_load_more_html( $page_size, $total, array $a ) {
	$scroll = 'scroll' === $a['loadMore'];
	ob_start();
	?>
	<div class="isgal-more"<?php echo $scroll ? ' data-wp-init--scroll="callbacks.watchMore"' : ''; ?> data-wp-watch--more="callbacks.syncMore">
		<p class="isgal-more__status" aria-live="polite">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: images shown so far, 2: images in the gallery */
					__( 'Showing %1$s of %2$s', 'image-snippets-gallery' ),
					'<span data-wp-text="state.shownCount">' . esc_html( number_format_i18n( min( $page_size, $total ) ) ) . '</span>',
					'<span data-wp-text="state.visibleCount">' . esc_html( number_format_i18n( $total ) ) . '</span>'
				),
				array( 'span' => array( 'data-wp-text' => array() ) )
			);
			?>
		</p>
		<button type="button" class="isgal-more__button" data-wp-on--click="actions.more"><?php esc_html_e( 'Load more', 'image-snippets-gallery' ); ?></button>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The controls under a slideshow: previous/next, a live "n / total" counter,
 * play/pause when autoplay is on, and either a dot or a thumbnail per image.
 * Dots and thumbnails know their place in the context's items, so a filtered
 * out image loses its dot along with its slide.
 *
 * @param array $thumbs One entry per item: [ src, alt ].
 * @param array $a      Resolved attributes.
 * @return string HTML.
 */
function isgal_slideshow_controls_html( array $thumbs, array $a ) {
	$nav  = in_array( $a['slideNav'], array( 'dots', 'thumbnails', 'none' ), true ) ? $a['slideNav'] : 'dots';
	$many = count( $thumbs ) > 1;
	ob_start();
	?>
	<div class="isgal-slides__controls">
		<?php if ( $many ) : ?>
		<button type="button" class="isgal-slides__nav isgal-slides__prev" data-wp-on--click="actions.slidePrev" aria-label="<?php esc_attr_e( 'Previous image', 'image-snippets-gallery' ); ?>">&#x2039;</button>
		<?php endif; ?>
		<span class="isgal-slides__count" data-wp-text="state.slidePosition" aria-live="polite"><?php echo esc_html( '1 / ' . number_format_i18n( count( $thumbs ) ) ); ?></span>
		<?php if ( $many && $a['slideAutoplay'] ) : ?>
		<button type="button" class="isgal-slides__play" data-wp-on--click="actions.togglePlay" aria-label="<?php esc_attr_e( 'Pause slideshow', 'image-snippets-gallery' ); ?>" data-wp-bind--aria-label="state.playLabel"><span data-wp-text="state.playGlyph">&#x23F8;</span></button>
		<?php endif; ?>
		<?php if ( $many ) : ?>
		<button type="button" class="isgal-slides__nav isgal-slides__next" data-wp-on--click="actions.slideNext" aria-label="<?php esc_attr_e( 'Next image', 'image-snippets-gallery' ); ?>">&#x203A;</button>
		<?php endif; ?>
	</div>
	<?php if ( $many && 'none' !== $nav ) : ?>
	<div class="isgal-slides__<?php echo esc_attr( $nav ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Choose image', 'image-snippets-gallery' ); ?>">
		<?php foreach ( $thumbs as $i => $thumb ) : ?>
			<button type="button" role="tab" class="isgal-slides__<?php echo 'dots' === $nav ? 'dot' : 'thumb'; ?>" data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'i' => $i ) ) ); ?>" data-wp-on--click="actions.slideTo" data-wp-bind--aria-selected="state.dotOn" data-wp-bind--hidden="state.itemHidden" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: position, 2: total */ __( 'Image %1$d of %2$d', 'image-snippets-gallery' ), $i + 1, count( $thumbs ) ) ); ?>">
				<?php if ( 'thumbnails' === $nav ) : ?>
					<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" loading="lazy" decoding="async" />
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

/**
 * Rows grouped by the year of their resolved date, for the timeline layout.
 * Rows are sorted by that date first (the block's direction), so a custom
 * date priority reorders the timeline as a person would expect. Undated
 * images come last under their own heading.
 *
 * @param array $rows Rows.
 * @param array $a    Resolved attributes.
 * @return array List of [ label, rows ].
 */
function isgal_timeline_groups( array $rows, array $a ) {
	$priority  = isgal_block_date_priority( $a );
	$ascending = 'asc' === strtolower( (string) $a['order'] );
	$dated     = array();
	$undated   = array();
	foreach ( $rows as $i => $row ) {
		$resolved = isgal_row_resolved_date( $row, $priority );
		if ( $resolved && ( $resolved['ts'] || preg_match( '/^\d{4}$/', $resolved['raw'] ) ) ) {
			$year    = $resolved['ts'] ? (int) gmdate( 'Y', $resolved['ts'] ) : (int) $resolved['raw'];
			$dated[] = array( $year, $resolved['ts'] ? $resolved['ts'] : mktime( 0, 0, 0, 1, 1, $year ), $i, $row );
		} else {
			$undated[] = $row;
		}
	}
	usort(
		$dated,
		function ( $x, $y ) use ( $ascending ) {
			if ( $x[1] !== $y[1] ) {
				return $ascending ? $x[1] - $y[1] : $y[1] - $x[1];
			}
			return $x[2] - $y[2];
		}
	);
	$groups = array();
	foreach ( $dated as $entry ) {
		$label = (string) $entry[0];
		if ( ! isset( $groups[ $label ] ) ) {
			$groups[ $label ] = array();
		}
		$groups[ $label ][] = $entry[3];
	}
	$out = array();
	foreach ( $groups as $label => $group_rows ) {
		$out[] = array( $label, $group_rows );
	}
	if ( $undated ) {
		$out[] = array( __( 'Undated', 'image-snippets-gallery' ), $undated );
	}
	return $out;
}

/**
 * The caption lines for one image, resolved from the row in the block's order.
 *
 * @param array $row   Row.
 * @param array $a     Resolved attributes.
 * @param string $title Already-resolved title (may use the filename fallback).
 * @return array List of [ field key, text ].
 */
function isgal_row_caption_lines( array $row, array $a, $title ) {
	$fields = is_array( $a['captionFields'] ) ? $a['captionFields'] : array( 'title' );
	$known  = array_keys( isgal_caption_fields() );
	$lines  = array();
	foreach ( $fields as $field ) {
		if ( ! in_array( $field, $known, true ) ) {
			continue;
		}
		$text = '';
		switch ( $field ) {
			case 'title':
				$text = (string) $title;
				break;
			case 'creator':
				$text = (string) $row['creator'];
				break;
			case 'date':
				$date = isgal_row_display_date( $row, $a );
				$text = $date['text'];
				break;
			case 'rights':
				$text = (string) $row['rights'];
				break;
		}
		if ( '' !== $text ) {
			$lines[] = array( $field, $text, 'date' === $field ? $date['source'] : '' );
		}
	}
	return $lines;
}

/**
 * Fill in attribute defaults. Shared by the renderer and the refresh route so
 * both derive the same SPARQL query, and therefore the same cache key.
 *
 * @param array $attributes Raw block attributes.
 * @return array
 */
function isgal_resolve_attributes( array $attributes ) {
	return wp_parse_args( $attributes, isgal_defaults() );
}

/**
 * The site-wide default SPARQL endpoint: the Tools screen setting, else the
 * built-in ImageSnippets endpoint.
 *
 * @return string
 */
/**
 * The galleries an endpoint offers, for the editor's picker.
 *
 * One SPARQL query, cached briefly per endpoint: the list changes only when
 * someone makes a gallery on ImageSnippets, and the picker is opened far more
 * often than that. Failures are not cached, so a blip does not blank the
 * picker for the whole cache period; the editor falls back to typing.
 *
 * Each entry: 'value' is what the block stores ("owner/gallery", or the bare
 * name for the default owner — see isgal_dataset_iri()), plus 'owner', 'name'
 * and 'count'. Sorted with the default owner first, then by owner and name.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param bool   $fresh    Bypass and replace the cache.
 * @return array|WP_Error
 */
function isgal_list_galleries( $endpoint, $fresh = false ) {
	$key = 'isgal_galleries_' . md5( (string) $endpoint );
	if ( ! $fresh ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$rows = isgal_sparql_json( $endpoint, isgal_build_sparql_datasets(), 15 );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	$list = array();
	foreach ( $rows as $row ) {
		$iri = isset( $row['ds']['value'] ) ? (string) $row['ds']['value'] : '';
		if ( 0 !== strpos( $iri, ISGAL_DATASET_BASE ) ) {
			continue;
		}
		$parts = explode( '/', substr( $iri, strlen( ISGAL_DATASET_BASE ) ) );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			continue;
		}
		$owner = rawurldecode( $parts[0] );
		$name  = rawurldecode( $parts[1] );
		// Only names the block can store unchanged are offered.
		if ( isgal_sanitize_iri_segment( $owner ) !== $owner || isgal_sanitize_iri_segment( $name ) !== $name ) {
			continue;
		}
		$list[] = array(
			'value' => ( ISGAL_DEFAULT_DATASET_OWNER === $owner ? '' : $owner . '/' ) . $name,
			'owner' => $owner,
			'name'  => $name,
			'count' => isset( $row['n']['value'] ) ? (int) $row['n']['value'] : 0,
		);
	}

	usort(
		$list,
		function ( $x, $y ) {
			$xd = ( ISGAL_DEFAULT_DATASET_OWNER === $x['owner'] ) ? 0 : 1;
			$yd = ( ISGAL_DEFAULT_DATASET_OWNER === $y['owner'] ) ? 0 : 1;
			if ( $xd !== $yd ) {
				return $xd - $yd;
			}
			return strcasecmp( $x['value'], $y['value'] );
		}
	);

	/**
	 * Filters how long the gallery list is cached, in seconds.
	 *
	 * @param int    $ttl      Seconds. Default 15 minutes.
	 * @param string $endpoint SPARQL endpoint URL.
	 */
	$ttl = (int) apply_filters( 'isgal_gallery_list_ttl', 15 * MINUTE_IN_SECONDS, $endpoint );
	set_transient( $key, $list, max( 60, $ttl ) );

	return $list;
}

function isgal_default_endpoint() {
	$url = esc_url_raw( (string) get_option( 'isgal_default_endpoint', '' ) );
	return '' !== $url ? $url : ISGAL_DEFAULT_ENDPOINT;
}

/**
 * The site-wide default refetch interval in minutes (Tools screen setting).
 *
 * @return int
 */
function isgal_default_ttl_minutes() {
	$minutes = get_option( 'isgal_default_ttl', 10 );
	$minutes = is_numeric( $minutes ) ? (int) $minutes : 10;
	return max( 0, min( 1440, $minutes ) );
}

/**
 * Resolve the SPARQL endpoint for a set of resolved attributes: the block's
 * own override if it has one, else the site default.
 *
 * @param array $a Resolved attributes.
 * @return string
 */
function isgal_resolve_endpoint( array $a ) {
	return ! empty( $a['endpoint'] ) ? esc_url_raw( $a['endpoint'] ) : isgal_default_endpoint();
}

/**
 * CSS value for the block's "Block spacing" (blockGap) setting, or '' if unset.
 *
 * Core only turns `style.spacing.blockGap` into CSS for blocks with `layout`
 * support (wp-includes/block-supports/layout.php); that support is meant for
 * container blocks and would bolt a Layout panel onto this leaf block. So we
 * declare the support and bridge the stored value ourselves, mirroring core's
 * preset conversion (`var:preset|spacing|40` → `var(--wp--preset--spacing--40)`)
 * and its character allow-list.
 *
 * @param array $attributes Raw block attributes.
 * @return string CSS length/var, or '' when unset or unsafe.
 */
function isgal_block_gap_css( array $attributes ) {
	$gap = $attributes['style']['spacing']['blockGap'] ?? null;
	if ( is_array( $gap ) ) {
		$gap = $gap['top'] ?? null;
	}
	if ( ! is_string( $gap ) || '' === $gap || preg_match( '%[\\\(&=}]|/\*%', $gap ) ) {
		return '';
	}
	if ( false !== strpos( $gap, 'var:preset|spacing|' ) ) {
		$slug = _wp_to_kebab_case( substr( $gap, strrpos( $gap, '|' ) + 1 ) );
		return 'var(--wp--preset--spacing--' . $slug . ')';
	}
	return $gap;
}

/**
 * Accept a single CSS value (length, colour, preset var) from a block
 * attribute, or return '' if it is empty or could break out of a declaration.
 *
 * Presets arrive as `var:preset|shadow|deep` and become
 * `var(--wp--preset--shadow--deep)`, as core does. Everything else must be a
 * plain token: no semicolons, braces, quotes, comments, or url()/expression().
 *
 * @param mixed $value Raw attribute value.
 * @return string CSS value or ''.
 */
function isgal_css_value( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}
	if ( preg_match( '#^var:preset\|([a-z-]+)\|([a-z0-9-]+)$#i', $value, $m ) ) {
		return 'var(--wp--preset--' . strtolower( $m[1] ) . '--' . _wp_to_kebab_case( $m[2] ) . ')';
	}
	if ( preg_match( '#[;{}"\'<>\\\\]|/\*|url\s*\(|expression\s*\(|@#i', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Turn a border value ({width,style,color} or one such object per side) into
 * custom properties for the thumbnails.
 *
 * @param mixed $border Attribute value.
 * @return array<string,string> Custom property name => value.
 */
function isgal_border_vars( $border ) {
	if ( ! is_array( $border ) ) {
		return array();
	}
	$shorthand = static function ( $b ) {
		if ( ! is_array( $b ) ) {
			return '';
		}
		$width = isgal_css_value( $b['width'] ?? '' );
		$style = isgal_css_value( $b['style'] ?? '' );
		$color = isgal_css_value( $b['color'] ?? '' );
		if ( '' === $width && '' === $color ) {
			return '';
		}
		// A width or colour with no style is invisible; solid is what the picker implies.
		return trim( ( '' !== $width ? $width : '1px' ) . ' ' . ( '' !== $style ? $style : 'solid' ) . ' ' . $color );
	};
	$sides     = array( 'top', 'right', 'bottom', 'left' );
	$vars      = array();
	if ( array_intersect_key( $border, array_flip( $sides ) ) ) {
		foreach ( $sides as $side ) {
			$v = $shorthand( $border[ $side ] ?? null );
			if ( '' !== $v ) {
				$vars[ '--isgal-img-border-' . $side ] = $v;
			}
		}
	} else {
		$v = $shorthand( $border );
		if ( '' !== $v ) {
			$vars['--isgal-img-border'] = $v;
		}
	}
	return $vars;
}

/**
 * Turn a radius value (string, or {topLeft,topRight,bottomRight,bottomLeft})
 * into the shorthand order border-radius expects.
 *
 * @param mixed $radius Attribute value.
 * @return string
 */
function isgal_radius_value( $radius ) {
	if ( is_array( $radius ) ) {
		$corners = array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' );
		$out     = array();
		foreach ( $corners as $c ) {
			$v     = isgal_css_value( $radius[ $c ] ?? '' );
			$out[] = '' !== $v ? $v : '0';
		}
		return implode( ' ', $out );
	}
	return isgal_css_value( $radius );
}

/**
 * Custom properties and gate classes for the block's own style settings
 * (image border/radius/shadow, separate title/caption colour and size).
 *
 * @param array $a Resolved attributes.
 * @return array{vars: array<string,string>, classes: string[]}
 */
function isgal_style_vars( array $a ) {
	$vars    = isgal_border_vars( $a['imageBorder'] );
	$classes = array();

	$radius = isgal_radius_value( $a['imageRadius'] );
	if ( '' !== $radius ) {
		$vars['--isgal-img-radius'] = $radius;
	}
	$shadow = isgal_css_value( $a['imageShadow'] );
	if ( '' !== $shadow ) {
		$vars['--isgal-img-shadow'] = $shadow;
	}

	$caption_bg = isgal_css_value( $a['captionBackground'] );
	if ( '' !== $caption_bg ) {
		$vars['--isgal-caption-bg'] = $caption_bg;
	}

	if ( ! empty( $a['separateText'] ) ) {
		foreach ( array(
			'titleColor'   => array( '--isgal-title-color', 'isgal-has-title-color' ),
			'titleSize'    => array( '--isgal-title-size', 'isgal-has-title-size' ),
			'captionColor' => array( '--isgal-caption-color', 'isgal-has-caption-color' ),
			'captionSize'  => array( '--isgal-caption-size', 'isgal-has-caption-size' ),
		) as $key => $target ) {
			$v = isgal_css_value( $a[ $key ] );
			if ( '' !== $v ) {
				$vars[ $target[0] ] = $v;
				$classes[]          = $target[1];
			}
		}
	}

	return array(
		'vars'    => $vars,
		'classes' => $classes,
	);
}

/**
 * Render the gallery HTML for a set of block attributes. Called from render.php.
 *
 * @param array $attributes Block attributes.
 * @return string HTML.
 */
function isgal_render_gallery( array $attributes ) {
	$a = isgal_resolve_attributes( $attributes );

	$endpoint = isgal_resolve_endpoint( $a );

	$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'justified', 'timeline', 'slideshow' ), true ) ? $a['layout'] : 'grid';
	$cols   = max( 1, min( 8, (int) $a['columns'] ) );
	// A slideshow shows one image at a time, as wide as the block: the column
	// setting does not apply, and the renditions are chosen for one column.
	if ( 'slideshow' === $layout ) {
		$cols = 1;
	}
	$ratio = in_array( $a['aspectRatio'], array( 'original', '1-1', '4-3', '3-2', '16-9' ), true ) ? $a['aspectRatio'] : '4-3';
	// Which Flickr rendition to request as the src, by how wide a column is.
	$size = $cols >= 5 ? 'small' : ( $cols >= 3 ? 'medium' : 'large' );
	// Aspect-ratio cropping is incompatible with true masonry (variable heights)
	// and with justified rows, which are sized from each image's own ratio.
	if ( 'masonry' === $layout || 'justified' === $layout ) {
		$ratio = 'original';
	}

	$lightbox    = 'lightbox' === $a['onClick'];
	$caption_pos = in_array( $a['captionPosition'], array( 'below', 'above', 'overlay', 'hover' ), true ) ? $a['captionPosition'] : 'below';
	$hover       = in_array( $a['hoverEffect'], array( 'none', 'zoom', 'fade', 'lift' ), true ) ? $a['hoverEffect'] : 'none';

	// How images arrive. 'auto' loads the first two rows with the page, since
	// they are what a visitor lands on and a lazy image there paints late, and
	// leaves the rest to the browser; 'lazy' and 'eager' are the two extremes.
	// 'fade' holds a tinted box where each image will be and fades it in.
	$loading = in_array( $a['imageLoading'], array( 'auto', 'lazy', 'eager' ), true ) ? $a['imageLoading'] : 'auto';
	$reveal  = in_array( $a['imageReveal'], array( 'fade', 'none' ), true ) ? $a['imageReveal'] : 'fade';
	if ( 'eager' === $loading ) {
		$eager = PHP_INT_MAX;
	} elseif ( 'lazy' === $loading ) {
		$eager = 0;
	} else {
		$eager = 'slideshow' === $layout ? 1 : min( 12, $cols * 2 );
	}
	/**
	 * Filter how many of a gallery's leading images load with the page rather
	 * than lazily.
	 *
	 * @param int   $eager Number of images.
	 * @param array $a     Resolved block attributes.
	 */
	$eager = max( 0, (int) apply_filters( 'isgal_eager_images', $eager, $a ) );

	$style   = isgal_style_vars( $a );
	$classes = implode(
		' ',
		array_merge(
			array( 'isgal-gallery', 'isgal-layout-' . $layout, 'isgal-ratio-' . $ratio, 'isgal-captions-' . $caption_pos, 'isgal-hover-' . $hover, 'isgal-reveal-' . $reveal, $lightbox ? 'isgal-has-lightbox' : '' ),
			$style['classes']
		)
	);
	$extra   = array( 'class' => $classes );

	// Column count, and the count phones get (never more than two).
	$style['vars']['--isgal-cols']    = (string) $cols;
	$style['vars']['--isgal-cols-sm'] = (string) min( 2, $cols );

	$gap = isgal_block_gap_css( $attributes );
	if ( '' !== $gap ) {
		$style['vars']['--isgal-gap'] = $gap;
	}
	if ( $style['vars'] ) {
		$decls = array();
		foreach ( $style['vars'] as $name => $value ) {
			$decls[] = $name . ':' . $value;
		}
		$extra['style'] = implode( ';', $decls );
	}
	$wrapper_attributes = get_block_wrapper_attributes( $extra );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return sprintf(
			'<div %s><p class="isgal-message">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Enter an ImageSnippets gallery name in the block settings.', 'image-snippets-gallery' )
		);
	}

	$rows = isgal_gallery_rows( $a, $endpoint );

	if ( is_wp_error( $rows ) ) {
		return sprintf(
			'<div %s><p class="isgal-message isgal-error">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Unable to load gallery right now.', 'image-snippets-gallery' )
		);
	}

	$use_filename = (bool) $a['useFilename'];
	$position     = 0;
	// Not in the editor: showModal() inside the preview iframe is a trap for
	// the person editing, and the items would be rebuilt on every keystroke.
	$lightbox = $lightbox && ! is_wp_error( $rows ) && ! empty( $rows ) && ! isgal_is_editor_preview();
	// The filter bar is built from the rows at render time: the whole gallery
	// is on the page already (p90 on ImageSnippets is 47 images), so filtering
	// is a state change over what is there, not a query. In the editor the bar
	// is drawn but inert, for the same reason the lightbox is off there.
	$facet_keys  = isgal_block_facets( $a );
	$facet_index = $facet_keys && ! empty( $rows ) ? isgal_facet_index( $rows, $a, $facet_keys ) : array();
	$facets_live = ! empty( $facet_index ) && ! isgal_is_editor_preview();
	// A slideshow is server-rendered whole: every figure is in the page, all
	// but the first carry `hidden`, and the script only moves that attribute
	// around. Crawlers, JSON-LD and no-script visitors see the full gallery
	// (the latter, the first image). In the editor the first slide and inert
	// controls stand in for it.
	$slideshow   = 'slideshow' === $layout && ! empty( $rows );
	$slides_live = $slideshow && ! isgal_is_editor_preview();
	// "Load more" is the same idea: the whole gallery is rendered, the figures
	// past the first batch carry `hidden`, and the script takes it off a batch
	// at a time. Nothing is fetched, the page cache holds one page, JSON-LD
	// and crawlers see every image. A slideshow already shows one at a time.
	$page_size   = max( 0, min( 200, (int) $a['pageSize'] ) );
	$paged       = $page_size > 0 && ! $slideshow && count( $rows ) > $page_size;
	$paged_live  = $paged && ! isgal_is_editor_preview();
	$interactive = $lightbox || $facets_live || $slides_live || $paged_live;
	$items       = array();
	$thumbs      = array();
	// The back of each photograph (its whole graph, as words) for the lightbox
	// flip. Kept out of the context: it is several KB an image, read only if
	// someone flips one, so it rides along as inert JSON and is parsed then.
	$flip  = $lightbox && ! empty( $a['lightboxFlip'] );
	$backs = array();
	if ( $interactive ) {
		$wrapper_attributes .= ' data-wp-interactive="imagesnippets/gallery" data-wp-init--hash="callbacks.openFromHash" data-wp-init--facets="callbacks.initFacets"';
	}
	if ( $flip ) {
		wp_interactivity_state(
			'imagesnippets/gallery',
			array(
				'flipI18n' => array(
					'toBack'  => __( 'Flip to see the metadata', 'image-snippets-gallery' ),
					'toFront' => __( 'Flip back to the image', 'image-snippets-gallery' ),
				),
			)
		);
	}
	if ( $slides_live ) {
		wp_interactivity_state(
			'imagesnippets/gallery',
			array(
				'i18n' => array(
					'play'  => __( 'Play slideshow', 'image-snippets-gallery' ),
					'pause' => __( 'Pause slideshow', 'image-snippets-gallery' ),
				),
			)
		);
		$wrapper_attributes .= ' data-wp-init--motion="callbacks.initMotion" data-wp-watch--autoplay="callbacks.autoplay" data-wp-on--keydown="actions.slideKeydown" data-wp-on--mouseenter="actions.hold" data-wp-on--mouseleave="actions.release" data-wp-on--focusin="actions.hold" data-wp-on--focusout="actions.release" data-wp-on--touchstart="actions.touchStart" data-wp-on--touchend="actions.slideTouchEnd" role="region" aria-roledescription="carousel" aria-label="' . esc_attr( $a['gallery'] ) . '"';
	}
	if ( $facet_index ) {
		$wrapper_attributes = str_replace( 'class="', 'class="isgal-has-facets ', $wrapper_attributes );
	}

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $interactive ? ' data-wp-context="' . esc_attr( 'ISGAL_CONTEXT_PLACEHOLDER' ) . '"' : ''; ?>>
		<?php echo isgal_editor_notice( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php
		if ( $a['displayTitle'] ) :
			$isgal_level = (int) $a['titleLevel'];
			$isgal_tag   = 'h' . ( $isgal_level >= 1 && $isgal_level <= 6 ? $isgal_level : 2 );
			?>
			<<?php echo $isgal_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h1..h6 only. ?> class="isgal-title"><?php echo esc_html( $a['gallery'] ); ?></<?php echo $isgal_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="isgal-message"><?php echo esc_html( sprintf( /* translators: %s: gallery name */ __( '%s — no images available.', 'image-snippets-gallery' ), $a['gallery'] ) ); ?></p>
		<?php else : ?>
			<?php echo $facet_index ? isgal_facet_bar_html( $facet_index, count( $rows ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
			<?php
			// A timeline is the same items grouped under year headings, in date
			// order; every other layout is one group with no heading.
			$groups = 'timeline' === $layout ? isgal_timeline_groups( $rows, $a ) : array( array( '', $rows ) );
			foreach ( $groups as $group ) :
				list( $isgal_group_label, $isgal_group_rows ) = $group;
				if ( '' !== $isgal_group_label ) :
					?>
					<section class="isgal-timeline__group">
						<h3 class="isgal-timeline__label"><?php echo esc_html( $isgal_group_label ); ?></h3>
				<?php endif; ?>
			<div class="isgal-grid">
				<?php
				foreach ( $isgal_group_rows as $row ) :
					++$position;
					$title = isgal_row_title( $row, $use_filename );
					$alt   = isgal_row_alt( $row, (string) $a['altSource'] );
					// Always give the link an accessible name, even when alt is empty.
					$label = isgal_first(
						array(
							$alt,
							$title,
							sprintf( /* translators: 1: gallery name, 2: position */ __( '%1$s image %2$d', 'image-snippets-gallery' ), $a['gallery'], $position ),
						)
					);
					// Anchor for search results, which link here with #fragment.
					$isgal_anchor = isgal_image_anchor( $row['page'] );
					// Justified rows are laid out from each image's own proportions
					// (isgal_row_dimensions()), with no script: the ratio becomes a
					// custom property the stylesheet turns into flex-basis/grow and an
					// aspect-ratio, so the row heights are known before any image loads.
					// Each figure knows its place in the context's items, which
					// carry its facet values. Hiding is a watch, not a bind: the
					// server evaluates data-wp-bind too, cannot see derived state,
					// and would strip the `hidden` set below before scripts run.
					$isgal_item_attrs = '';
					if ( $interactive ) {
						$isgal_facet_values = array();
						foreach ( $facet_keys as $isgal_facet ) {
							$isgal_facet_values[ $isgal_facet ] = array_values( array_unique( array_map( 'isgal_facet_key', isgal_row_facet_values( $row, $a )[ $isgal_facet ] ) ) );
						}
						$isgal_item = array(
							'anchor' => $isgal_anchor,
							'f'      => (object) $isgal_facet_values,
						);
						if ( $slides_live ) {
							$isgal_item_attrs = ' data-wp-context="' . esc_attr( wp_json_encode( array( 'i' => count( $items ) ) ) ) . '" data-wp-watch--hidden="callbacks.syncSlide"';
						} elseif ( $facets_live || $paged_live ) {
							$isgal_item_attrs = ' data-wp-context="' . esc_attr( wp_json_encode( array( 'i' => count( $items ) ) ) ) . '" data-wp-watch--hidden="callbacks.syncItem"';
						}
					}
					if ( ( $slideshow && $position > 1 ) || ( $paged && $position > $page_size ) ) {
						$isgal_item_attrs .= ' hidden';
					}
					$isgal_item_style = '';
					if ( 'justified' === $layout ) {
						$isgal_jdims      = isgal_row_dimensions( $row );
						$isgal_ratio      = $isgal_jdims ? $isgal_jdims[0] / $isgal_jdims[1] : 4 / 3;
						$isgal_item_style = ' style="--isgal-r:' . esc_attr( round( max( 0.25, min( 4, $isgal_ratio ) ), 4 ) ) . '"';
					}
					?>
					<?php
					$isgal_lines = $a['displayCaption'] ? isgal_row_caption_lines( $row, $a, $title ) : array();
					if ( 'timeline' === $layout && ! in_array( 'date', wp_list_pluck( $isgal_lines, 0 ), true ) ) {
						// A timeline without dates on the items would be a grid with headings.
						$isgal_when = isgal_row_display_date( $row, $a );
						if ( '' !== $isgal_when['text'] ) {
							$isgal_lines[] = array( 'date', $isgal_when['text'], $isgal_when['source'] );
						}
					}
					$isgal_caption = '';
					if ( $isgal_lines ) {
						$isgal_caption = '<figcaption class="isgal-caption">';
						foreach ( $isgal_lines as $isgal_line ) {
							$isgal_caption .= '<span class="isgal-cap isgal-cap-' . esc_attr( $isgal_line[0] ) . '"'
								. ( 'title' === $isgal_line[0] ? ' property="name"' : '' )
								. ( '' !== $isgal_line[2] ? ' title="' . esc_attr( $isgal_line[2] ) . '"' : '' )
								. '>' . esc_html( $isgal_line[1] ) . '</span>';
						}
						$isgal_caption .= '</figcaption>';
					}
					// Hidden figures (a later "Load more" batch) stay lazy whatever the
					// setting: loading them with the page is what paging is there to avoid.
					$isgal_waiting = $paged && $position > $page_size;
					$isgal_eager   = $position <= $eager && ! $isgal_waiting;
					$isgal_dims    = isgal_row_dimensions( $row );
					?>
					<figure class="isgal-item"<?php echo '' !== $isgal_anchor ? ' id="' . esc_attr( $isgal_anchor ) . '"' : ''; ?><?php echo $isgal_item_style . $isgal_item_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> vocab="https://schema.org/" typeof="ImageObject">
						<?php echo 'above' === $caption_pos ? $isgal_caption : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						<a href="<?php echo esc_url( $row['page'] ? $row['page'] : '#' ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo $a['linkNewTab'] ? ' target="_blank" rel="noopener"' : ''; ?><?php echo $lightbox ? ' data-wp-on--click="actions.open"' : ''; ?>>
							<?php
							// The source URL (contentUrl) is the full-res original; for Flickr it
							// carries the size in its filename suffix, so we request a rendition
							// matched to the column instead of the legacy 128px IS thumbnail (which
							// the old srcset mislabeled as 500w → blur). The thumbnail survives only
							// as an onerror fallback for link-rotted 2013-era source URLs.
							// Prefer the explicit contentUrl, else the image IRI (itself the full-res
							// source URL), and only fall back to the 128px thumbnail as a last resort.
							$isgal_source = $row['content'];
							if ( '' === $isgal_source && preg_match( '#^https?://#i', $row['image'] ) ) {
								$isgal_source = $row['image'];
							}
							if ( '' === $isgal_source ) {
								$isgal_source = $row['thumb'];
							}
							$isgal_src_map = array(
								'small'  => 'n',
								'medium' => 'z',
								'large'  => 'c',
							);
							$isgal_code    = isset( $isgal_src_map[ $size ] ) ? $isgal_src_map[ $size ] : 'z';
							$isgal_src     = isgal_flickr_sized( $isgal_source, $isgal_code );
							$isgal_srcset  = isgal_flickr_srcset( $isgal_source );
							if ( $interactive ) {
								$items[] = $lightbox ? array_merge( $isgal_item, isgal_lightbox_item( $row, $a, $title, $alt, $isgal_source ) ) : $isgal_item;
							}
							if ( $flip && '' !== $isgal_anchor ) {
								$backs[ $isgal_anchor ] = isgal_row_back( $row );
							}
							if ( $slideshow ) {
								// The picker strip wants something small: ImageSnippets' own
								// 128px thumbnail when the row has one, else Flickr's square.
								$thumbs[] = array( '' !== $row['thumb'] ? $row['thumb'] : isgal_flickr_sized( $isgal_source, 'q' ), $alt );
							}
							// One column's share of the viewport; phones cap at two columns.
							$isgal_sizes = sprintf( '(max-width: 600px) %dvw, %dvw', (int) ( 100 / min( 2, $cols ) ), (int) ceil( 100 / $cols ) );
							?>
							<img
								src="<?php echo esc_url( $isgal_src ); ?>"
								<?php if ( '' !== $isgal_srcset ) : ?>
								srcset="<?php echo esc_attr( $isgal_srcset ); ?>"
								sizes="<?php echo esc_attr( $isgal_sizes ); ?>"
								<?php endif; ?>
								<?php if ( $row['thumb'] && $row['thumb'] !== $isgal_src ) : ?>
								onerror='this.onerror=null;this.removeAttribute("srcset");this.src="<?php echo esc_url( $row['thumb'] ); ?>";'
								<?php endif; ?>
								alt="<?php echo esc_attr( $alt ); ?>"
								<?php if ( $isgal_dims ) : // Reserves the image's own shape before it loads; the crop ratios override it in CSS. ?>
								width="<?php echo (int) $isgal_dims[0]; ?>" height="<?php echo (int) $isgal_dims[1]; ?>"
								<?php endif; ?>
								<?php if ( 'fade' === $reveal ) : ?>
								onload="this.classList.add('is-loaded')"
								<?php endif; ?>
								loading="<?php echo $isgal_eager ? 'eager' : 'lazy'; ?>"
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
						<?php echo 'above' !== $caption_pos ? $isgal_caption : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
					</figure>
				<?php endforeach; ?>
			</div>
				<?php if ( '' !== $isgal_group_label ) : ?>
					</section>
			<?php endif; ?>
			<?php endforeach; ?>
			<?php echo $slideshow ? isgal_slideshow_controls_html( $thumbs, $a ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
			<?php echo $paged ? isgal_load_more_html( $page_size, count( $rows ), $a ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
			<?php
			// One rights line for the whole gallery, but only when it is true of
			// every image shown; otherwise it would misattribute someone's work.
			$isgal_rights = array_unique( array_map( 'strval', wp_list_pluck( $rows, 'rights' ) ) );
			if ( 1 === count( $isgal_rights ) && '' !== reset( $isgal_rights ) ) :
				?>
				<p class="isgal-footer"><?php echo esc_html( sprintf( /* translators: %s: rights statement */ __( 'Images %s', 'image-snippets-gallery' ), reset( $isgal_rights ) ) ); ?></p>
			<?php endif; ?>
			<?php echo isgal_jsonld( $rows, $a['gallery'], $use_filename, $a['jsonldProfile'], isgal_current_permalink() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $lightbox ? isgal_lightbox_html( $flip ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
			<?php if ( $backs ) : ?>
				<script type="application/json" class="isgal-backs"><?php echo wp_json_encode( $backs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with tags hex-escaped. ?></script>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	$html = ob_get_clean();
	if ( $interactive ) {
		// shown: 0 means every image is displayed; otherwise how many are, and
		// batch is how many each "Load more" adds.
		$context = array(
			'lightbox' => $lightbox,
			'open'     => false,
			'index'    => 0,
			'canFlip'  => $flip,
			'flipped'  => false,
			'loading'  => false,
			'backs'    => null,
			'items'    => $items,
			'facets'   => $facet_keys,
			'active'   => (object) array_fill_keys( $facet_keys, array() ),
			'slides'   => $slides_live,
			'slide'    => 0,
			'autoplay' => $slides_live && (bool) $a['slideAutoplay'],
			'interval' => max( 2, min( 60, (int) $a['slideInterval'] ) ) * 1000,
			'playing'  => $slides_live && (bool) $a['slideAutoplay'],
			'held'     => false,
			'shown'    => $paged_live ? $page_size : 0,
			'batch'    => $page_size,
			'scroll'   => $paged_live && 'scroll' === $a['loadMore'],
		);
		$html    = str_replace( esc_attr( 'ISGAL_CONTEXT_PLACEHOLDER' ), esc_attr( wp_json_encode( $context ) ), $html );
	}
	return $html;
}
