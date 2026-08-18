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
	$out    = array();
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
 * Lock key that keeps a burst of traffic from scheduling N background syncs of
 * the same gallery.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return string
 */
function isg_lock_key( $endpoint, $gallery ) {
	return 'isg3lock_' . md5( $endpoint . '|' . $gallery );
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
function isg_configured_ttl( array $a ) {
	$minutes = isset( $a['cacheTtl'] ) ? (int) $a['cacheTtl'] : 10;
	return max( 0, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;
}

/**
 * Resolve the sync interval for the current request, applying the editor cap.
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
	 * Filter the sync interval in seconds. Return 0 to sync on every render.
	 *
	 * @param int   $ttl Resolved interval in seconds.
	 * @param array $a   Resolved block attributes.
	 */
	return max( 0, (int) apply_filters( 'isg_cache_ttl', $ttl, $a ) );
}

/**
 * Queue a background sync for a gallery that is past its interval.
 *
 * The lock stores the time it was taken, not a flag, so a later read can tell
 * whether the scheduled job ever ran. See isg_cron_stalled().
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return void
 */
function isg_schedule_sync( $endpoint, $gallery ) {
	$lock = isg_lock_key( $endpoint, $gallery );
	if ( false !== get_transient( $lock ) ) {
		return;
	}
	// Held for 5 minutes and cleared on success. A failed sync keeps the lock
	// until it expires, which rate-limits retries against a struggling endpoint.
	set_transient( $lock, time(), 5 * MINUTE_IN_SECONDS );

	wp_schedule_single_event( time(), 'isg_sync_gallery', array( $endpoint, (string) $gallery ) );

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
function isg_cron_stalled( $endpoint, $gallery ) {
	$queued_at = get_transient( isg_lock_key( $endpoint, $gallery ) );
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

	$existing                           = isset( $status[ $gallery ] ) && is_array( $status[ $gallery ] ) ? $status[ $gallery ] : array();
	$status[ $gallery ]                 = array_merge( $existing, $fields );
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
function isg_refresh_gallery( $endpoint, $gallery ) {
	delete_transient( isg_lock_key( $endpoint, $gallery ) );
	return isg_sync_gallery(
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
function isg_refresh_all_galleries() {
	$results = array();
	$done    = array();

	// The sync unit is (endpoint, gallery). Two blocks showing the same gallery
	// with different limits or sorting read the same mirror, so one sync serves
	// them both.
	foreach ( isg_indexed_gallery_blocks() as $block ) {
		$a        = isg_resolve_attributes( $block['attrs'] );
		$gallery  = $block['gallery'];
		$endpoint = isg_resolve_endpoint( $a );

		$key = $endpoint . '|' . $gallery;
		if ( isset( $done[ $key ] ) ) {
			continue;
		}
		$done[ $key ] = true;

		$result = isg_refresh_gallery( $endpoint, $gallery );

		// Keyed by gallery for display; a gallery can appear on more than one
		// endpoint, so a later success must not silently replace an error.
		if ( ! isset( $results[ $gallery ] ) || is_wp_error( $result ) ) {
			$results[ $gallery ] = $result;
		}
	}

	isg_prune_mirror();

	return $results;
}

/**
 * The rows for a block: read from the mirror, syncing first if it is missing
 * or stale enough that waiting is the right call.
 *
 * The rungs, in order:
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
function isg_gallery_rows( array $a, $endpoint ) {
	$gallery = trim( (string) $a['gallery'] );
	$ttl     = isg_resolve_ttl( $a );
	$term    = isg_gallery_term( $endpoint, $gallery );
	$synced  = isg_gallery_synced_at( $term );

	// Inline syncs run inside someone's page load. Shorter ceiling than cron.
	$inline = array( 'timeout' => (int) apply_filters( 'isg_inline_sync_timeout', 10 ) );

	if ( 0 === $synced ) {
		$result = isg_sync_gallery( $endpoint, $gallery, $inline );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = isg_gallery_term( $endpoint, $gallery );
	} elseif ( ( time() - $synced ) >= $ttl ) {
		if ( 0 === $ttl || isg_is_editor_preview() || isg_cron_stalled( $endpoint, $gallery ) ) {
			// Failure here is not fatal: the mirror still holds the last good copy.
			isg_sync_gallery( $endpoint, $gallery, $inline );
		} else {
			isg_schedule_sync( $endpoint, $gallery );
		}
	}

	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'isg_no_mirror', __( 'Gallery has not been synchronised yet.', 'image-snippets-gallery' ) );
	}

	return isg_mirror_query_rows( $term, $a );
}

/**
 * Run a SPARQL query and return its bindings. No caching, no interpretation.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @param int    $timeout  Seconds.
 * @return array|WP_Error  Bindings, or WP_Error.
 */
function isg_sparql_json( $endpoint, $query, $timeout = 20 ) {
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
		return new WP_Error( 'isg_http', sprintf( 'SPARQL endpoint returned HTTP %d', $code ) );
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! isset( $json['results']['bindings'] ) || ! is_array( $json['results']['bindings'] ) ) {
		return new WP_Error( 'isg_parse', 'Unexpected SPARQL response format.' );
	}

	return $json['results']['bindings'];
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
		'titleLevel'     => 2,
		'layout'         => 'grid',
		'order'          => 'desc',
		'orderBy'        => 'date',
		'limit'          => 40,
		'thumbSize'      => 'medium',
		'aspectRatio'    => '4-3',
		'useFilename'    => false,
		'cacheTtl'       => 10,
		'jsonldProfile'  => 'provenance',
		'imageBorder'    => null,
		'imageRadius'    => null,
		'imageShadow'    => '',
		'separateText'   => false,
		'titleColor'     => '',
		'titleSize'      => '',
		'captionColor'   => '',
		'captionSize'    => '',
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
function isg_block_gap_css( array $attributes ) {
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
function isg_css_value( $value ) {
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
function isg_border_vars( $border ) {
	if ( ! is_array( $border ) ) {
		return array();
	}
	$shorthand = static function ( $b ) {
		if ( ! is_array( $b ) ) {
			return '';
		}
		$width = isg_css_value( $b['width'] ?? '' );
		$style = isg_css_value( $b['style'] ?? '' );
		$color = isg_css_value( $b['color'] ?? '' );
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
				$vars[ '--isg-img-border-' . $side ] = $v;
			}
		}
	} else {
		$v = $shorthand( $border );
		if ( '' !== $v ) {
			$vars['--isg-img-border'] = $v;
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
function isg_radius_value( $radius ) {
	if ( is_array( $radius ) ) {
		$corners = array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' );
		$out     = array();
		foreach ( $corners as $c ) {
			$v     = isg_css_value( $radius[ $c ] ?? '' );
			$out[] = '' !== $v ? $v : '0';
		}
		return implode( ' ', $out );
	}
	return isg_css_value( $radius );
}

/**
 * Custom properties and gate classes for the block's own style settings
 * (image border/radius/shadow, separate title/caption colour and size).
 *
 * @param array $a Resolved attributes.
 * @return array{vars: array<string,string>, classes: string[]}
 */
function isg_style_vars( array $a ) {
	$vars    = isg_border_vars( $a['imageBorder'] );
	$classes = array();

	$radius = isg_radius_value( $a['imageRadius'] );
	if ( '' !== $radius ) {
		$vars['--isg-img-radius'] = $radius;
	}
	$shadow = isg_css_value( $a['imageShadow'] );
	if ( '' !== $shadow ) {
		$vars['--isg-img-shadow'] = $shadow;
	}

	if ( ! empty( $a['separateText'] ) ) {
		foreach ( array(
			'titleColor'   => array( '--isg-title-color', 'isg-has-title-color' ),
			'titleSize'    => array( '--isg-title-size', 'isg-has-title-size' ),
			'captionColor' => array( '--isg-caption-color', 'isg-has-caption-color' ),
			'captionSize'  => array( '--isg-caption-size', 'isg-has-caption-size' ),
		) as $key => $target ) {
			$v = isg_css_value( $a[ $key ] );
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
function isg_render_gallery( array $attributes ) {
	$a = isg_resolve_attributes( $attributes );

	$endpoint = isg_resolve_endpoint( $a );

	$layout = in_array( $a['layout'], array( 'grid', 'masonry' ), true ) ? $a['layout'] : 'grid';
	$size   = in_array( $a['thumbSize'], array( 'small', 'medium', 'large' ), true ) ? $a['thumbSize'] : 'medium';
	$ratio  = in_array( $a['aspectRatio'], array( 'original', '1-1', '4-3', '3-2', '16-9' ), true ) ? $a['aspectRatio'] : '4-3';
	// Aspect-ratio cropping is incompatible with true masonry (variable heights).
	if ( 'masonry' === $layout ) {
		$ratio = 'original';
	}

	$style   = isg_style_vars( $a );
	$classes = implode( ' ', array_merge( array( 'isg-gallery', 'isg-layout-' . $layout, 'isg-size-' . $size, 'isg-ratio-' . $ratio ), $style['classes'] ) );
	$extra   = array( 'class' => $classes );
	$gap     = isg_block_gap_css( $attributes );
	if ( '' !== $gap ) {
		$style['vars']['--isg-gap'] = $gap;
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
			'<div %s><p class="isg-message">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Enter an ImageSnippets gallery name in the block settings.', 'image-snippets-gallery' )
		);
	}

	$rows = isg_gallery_rows( $a, $endpoint );

	if ( is_wp_error( $rows ) ) {
		return sprintf(
			'<div %s><p class="isg-message isg-error">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Unable to load gallery right now.', 'image-snippets-gallery' )
		);
	}

	$use_filename = (bool) $a['useFilename'];
	$position     = 0;

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php echo isg_editor_notice( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php
		if ( $a['displayTitle'] ) :
			$isg_level = (int) $a['titleLevel'];
			$isg_tag   = 'h' . ( $isg_level >= 1 && $isg_level <= 6 ? $isg_level : 2 );
			?>
			<<?php echo $isg_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- h1..h6 only. ?> class="isg-title"><?php echo esc_html( $a['gallery'] ); ?></<?php echo $isg_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
							$isg_src_map = array(
								'small'  => 'n',
								'medium' => 'z',
								'large'  => 'c',
							);
							$isg_code    = isset( $isg_src_map[ $size ] ) ? $isg_src_map[ $size ] : 'z';
							$isg_src     = isg_flickr_sized( $isg_source, $isg_code );
							$isg_srcset  = isg_flickr_srcset( $isg_source );
							// Column min-widths from style.scss (small 120 / medium 200 / large 320),
							// with headroom since columns stretch to fill (auto-fill, 1fr).
							$isg_sizes_map = array(
								'small'  => '160px',
								'medium' => '260px',
								'large'  => '420px',
							);
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
			// One rights line for the whole gallery, but only when it is true of
			// every image shown; otherwise it would misattribute someone's work.
			$isg_rights = array_unique( array_map( 'strval', wp_list_pluck( $rows, 'rights' ) ) );
			if ( 1 === count( $isg_rights ) && '' !== reset( $isg_rights ) ) :
				?>
				<p class="isg-footer"><?php echo esc_html( sprintf( /* translators: %s: rights statement */ __( 'Images %s', 'image-snippets-gallery' ), reset( $isg_rights ) ) ); ?></p>
			<?php endif; ?>
			<?php echo isg_jsonld( $rows, $a['gallery'], $use_filename, $a['jsonldProfile'], isg_current_permalink() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
