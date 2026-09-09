<?php
/**
 * The mirror: ImageSnippets named graphs stored as WordPress posts.
 *
 * Every image on ImageSnippets is its own named graph, and that graph is the
 * natural unit of synchronisation. This file keeps one hidden post per graph,
 * labels it with the galleries it belongs to, and refreshes the lot by diffing
 * the live gallery against what is stored. Rendering then reads local rows with
 * WP_Query and never touches the network in a reader's request.
 *
 * Nothing here is visible in wp-admin: no menu, no list table, nothing in the
 * inserter, nothing in sitemaps or the REST index. The site owner should never
 * need to know the mirror exists. The only surfaces are the Tools screen and
 * Site Health, which report freshness.
 *
 * Deliberately not stored: anything about the ImageSnippets *page* beyond its
 * IRI. The graph is kept whole and untrimmed; the payload profiles trim at
 * render time, so nothing is lost by mirroring.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ISG_POST_TYPE = 'isg_image';
const ISG_TAXONOMY  = 'isg_gallery';
// Object-cache group for decoded rows. Registered non-persistent in
// isg_register_mirror_types(): this is a per-request memo, not a second store.
const ISG_ROW_CACHE_GROUP = 'isg_rows';

const ISG_META_PAGE     = '_isg_page';     // Named-graph IRI: the identity of the mirrored image.
const ISG_META_IMAGE    = '_isg_image';    // Image IRI.
const ISG_META_HASH     = '_isg_hash';     // Fingerprint of the stored graph, for the diff.
const ISG_META_ROW      = '_isg_row';      // The full row: display fields, triples, labels. JSON.
const ISG_META_DATE     = '_isg_date';     // Sort key: photoshop:DateCreated as stored.
const ISG_META_TITLE    = '_isg_title';    // Sort key: dc:title.
const ISG_META_CREATOR  = '_isg_creator';  // dcterms:creator of the graph, for the user filter. Repeatable.
const ISG_TERM_ENDPOINT = 'isg_endpoint';  // Term meta: which SPARQL endpoint the gallery lives on.
const ISG_TERM_GALLERY  = 'isg_gallery';   // Term meta: the gallery name, verbatim.
const ISG_TERM_SYNCED   = 'isg_last_sync'; // Term meta: unix time of the last successful sync.

// Images per graph-fetch request. ~1.4s per 40 against the live endpoint.
const ISG_SYNC_BATCH = 40;
// Ceiling on images listed for one gallery. Well beyond any real gallery; the
// point is that a runaway result cannot exhaust memory. Filterable.
const ISG_SYNC_MAX_IMAGES = 2000;

/**
 * Register the post type and the gallery taxonomy.
 *
 * Every visibility flag is off. exclude_from_search is the one deliberate
 * exception: front-end search queries 'any' post type that is not excluded,
 * so this alone puts mirrored images into the site's search results.
 *
 * @return void
 */
function isg_register_mirror_types() {
	wp_cache_add_non_persistent_groups( array( ISG_ROW_CACHE_GROUP ) );

	register_post_type(
		ISG_POST_TYPE,
		array(
			'label'               => __( 'ImageSnippets images', 'image-snippets-gallery' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			'can_export'          => false,
			'delete_with_user'    => false,
			'supports'            => array( 'title', 'editor', 'excerpt' ),
			'taxonomies'          => array( ISG_TAXONOMY ),
		)
	);

	register_taxonomy(
		ISG_TAXONOMY,
		ISG_POST_TYPE,
		array(
			'label'              => __( 'ImageSnippets galleries', 'image-snippets-gallery' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'show_tagcloud'      => false,
			'show_admin_column'  => false,
			'query_var'          => false,
			'rewrite'            => false,
			'hierarchical'       => false,
		)
	);
}
add_action( 'init', 'isg_register_mirror_types', 5 );

/**
 * Term slug for a gallery on an endpoint.
 *
 * The default endpoint gets the plain name; an override gets a suffix, so the
 * same gallery name on two endpoints is two labels rather than one confused one.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return string
 */
function isg_gallery_term_slug( $endpoint, $gallery ) {
	$slug = sanitize_title( $gallery );
	if ( '' === $slug ) {
		$slug = 'gallery-' . substr( md5( $gallery ), 0, 8 );
	}
	if ( ISG_DEFAULT_ENDPOINT !== $endpoint ) {
		$slug .= '-' . substr( md5( $endpoint ), 0, 8 );
	}
	return $slug;
}

/**
 * The gallery's label term, optionally creating it.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @param bool   $create   Create it if missing.
 * @return WP_Term|null
 */
function isg_gallery_term( $endpoint, $gallery, $create = false ) {
	$gallery = trim( (string) $gallery );
	if ( '' === $gallery ) {
		return null;
	}

	$slug = isg_gallery_term_slug( $endpoint, $gallery );
	$term = get_term_by( 'slug', $slug, ISG_TAXONOMY );
	if ( $term instanceof WP_Term ) {
		return $term;
	}
	if ( ! $create ) {
		return null;
	}

	$inserted = wp_insert_term( $gallery, ISG_TAXONOMY, array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		// A race with a concurrent sync: the term now exists, so read it back.
		$term = get_term_by( 'slug', $slug, ISG_TAXONOMY );
		return $term instanceof WP_Term ? $term : null;
	}

	update_term_meta( $inserted['term_id'], ISG_TERM_ENDPOINT, $endpoint );
	update_term_meta( $inserted['term_id'], ISG_TERM_GALLERY, $gallery );

	$term = get_term( $inserted['term_id'], ISG_TAXONOMY );
	return $term instanceof WP_Term ? $term : null;
}

/**
 * When a gallery was last synced successfully, or 0 if never.
 *
 * @param WP_Term|null $term Gallery term.
 * @return int
 */
function isg_gallery_synced_at( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return 0;
	}
	return (int) get_term_meta( $term->term_id, ISG_TERM_SYNCED, true );
}

/**
 * Fingerprint of one image's graph.
 *
 * Triples are sorted first: the endpoint returns them in whatever order it
 * likes, and an order-sensitive hash would call every sync a change.
 *
 * @param array $row Row.
 * @return string
 */
function isg_row_hash( array $row ) {
	$lines = array();
	foreach ( $row['triples'] as $triple ) {
		list( $s, $p, $o ) = $triple;
		$lines[]           = $s . "\t" . $p . "\t" . wp_json_encode( $o );
	}
	sort( $lines, SORT_STRING );
	return md5( $row['image'] . "\n" . implode( "\n", $lines ) );
}

/**
 * The text WordPress search should match an image on.
 *
 * Title, description and alt text as written, then every entity label and
 * keyword the graph carries — so a search for "rafter" or "Barrel Dormer" finds
 * the image even when neither word appears in its title.
 *
 * @param array $row Row.
 * @return string
 */
function isg_row_search_text( array $row ) {
	$parts = array(
		$row['title'],
		$row['name'],
		$row['desc'],
		$row['alt'],
		$row['creator'],
		$row['rights'],
	);
	foreach ( $row['abouts'] as $about ) {
		$parts[] = $about['label'];
	}
	foreach ( $row['keywords'] as $keyword ) {
		$parts[] = $keyword;
	}
	if ( isset( $row['labels'][ $row['location'] ] ) ) {
		$parts[] = $row['labels'][ $row['location'] ];
	}
	$parts = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) ) ) );
	return implode( "\n", $parts );
}

/**
 * dcterms:creator IRIs asserted on the graph itself (as opposed to the image).
 * This is what the block's user filter matches.
 *
 * @param array $row Row.
 * @return array
 */
function isg_row_graph_creators( array $row ) {
	$creators = array();
	foreach ( $row['triples'] as $triple ) {
		list( $s, $p, $o ) = $triple;
		if ( $s === $row['page'] && 'http://purl.org/dc/terms/creator' === $p && isset( $o['value'] ) ) {
			$creators[ $o['value'] ] = true;
		}
	}
	return array_keys( $creators );
}

/**
 * Existing mirror posts for a set of graph IRIs.
 *
 * Direct SQL for the same reason index.php uses it: postmeta writes do not
 * invalidate WP_Query's result cache, and this lookup runs immediately after
 * writes in the same sync.
 *
 * @param array $pages Graph IRIs.
 * @return array Map of graph IRI to post ID.
 */
function isg_mirror_posts_for_pages( array $pages ) {
	global $wpdb;

	$pages = array_values( array_unique( array_filter( array_map( 'strval', $pages ) ) ) );
	if ( empty( $pages ) ) {
		return array();
	}

	$map = array();
	foreach ( array_chunk( $pages, 200 ) as $chunk ) {
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		// Direct query on purpose: one IN() lookup per 200 IRIs instead of 200 WP_Query calls during sync.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list is built from count( $chunk ); the sniff cannot see it.
				"SELECT pm.post_id, pm.meta_value
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND p.post_type = %s
				    AND pm.meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( ISG_META_PAGE, ISG_POST_TYPE ), $chunk )
			)
		);
		foreach ( (array) $rows as $r ) {
			$map[ (string) $r->meta_value ] = (int) $r->post_id;
		}
	}
	return $map;
}

/**
 * The timestamp to date a mirror post with, or 0 to let WordPress use now.
 *
 * photoshop:DateCreated is free-form. A bare year ("2009") is common, and
 * strtotime() reads that as a clock time — 20:09 today — which is in the
 * future, and wp_insert_post() quietly turns a future-dated post into a
 * scheduled one that the gallery then does not show. So partial dates are
 * completed to their first day, and anything still in the future is dropped:
 * a post's date only sorts the admin list, so a wrong one is worse than none.
 *
 * @param string $raw photoshop:DateCreated as stored.
 * @return int Unix timestamp, or 0.
 */
function isg_mirror_date_ts( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return 0;
	}
	if ( preg_match( '/^\d{4}$/', $raw ) ) {
		$raw .= '-01-01';
	} elseif ( preg_match( '/^\d{4}-\d{1,2}$/', $raw ) ) {
		$raw .= '-01';
	}
	$ts = strtotime( $raw );
	if ( ! $ts || $ts > time() ) {
		return 0;
	}
	return $ts;
}

/**
 * Write one row to its mirror post, creating the post if needed.
 *
 * @param array    $row     Row.
 * @param int|null $post_id Existing post ID, or null to create.
 * @return int|WP_Error Post ID.
 */
function isg_mirror_write_row( array $row, $post_id = null ) {
	$title = isg_row_title( $row, true );
	if ( '' === $title ) {
		$title = $row['image'];
	}

	$date = '';
	$ts   = isg_mirror_date_ts( $row['date'] );
	if ( $ts ) {
		$date = gmdate( 'Y-m-d H:i:s', $ts );
	}

	$postarr = array(
		'post_type'      => ISG_POST_TYPE,
		'post_status'    => 'publish',
		'post_title'     => $title,
		'post_name'      => md5( $row['page'] ),
		'post_content'   => isg_row_search_text( $row ),
		'post_excerpt'   => isg_first( array( $row['desc'], $row['alt'] ) ),
		'comment_status' => 'closed',
		'ping_status'    => 'closed',
	);
	if ( '' !== $date ) {
		$postarr['post_date_gmt'] = $date;
		$postarr['post_date']     = get_date_from_gmt( $date );
	}

	// wp_insert_post expects slashed input, as if it came from a form.
	if ( $post_id ) {
		$postarr['ID'] = (int) $post_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$result = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$post_id = (int) $result;

	update_post_meta( $post_id, ISG_META_PAGE, wp_slash( $row['page'] ) );
	update_post_meta( $post_id, ISG_META_IMAGE, wp_slash( $row['image'] ) );
	update_post_meta( $post_id, ISG_META_HASH, isg_row_hash( $row ) );
	update_post_meta( $post_id, ISG_META_ROW, wp_slash( wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	isg_mirror_forget_row( $post_id );
	update_post_meta( $post_id, ISG_META_DATE, wp_slash( $row['date'] ) );
	update_post_meta( $post_id, ISG_META_TITLE, wp_slash( $row['title'] ) );

	delete_post_meta( $post_id, ISG_META_CREATOR );
	foreach ( isg_row_graph_creators( $row ) as $creator ) {
		add_post_meta( $post_id, ISG_META_CREATOR, wp_slash( $creator ) );
	}

	return $post_id;
}

/**
 * The stored row for a mirror post.
 *
 * Memoised for the length of the request. The row is one JSON blob holding the
 * image's whole named graph — a few hundred triples — and a search result asks
 * for it once per question the theme puts to it: the thumbnail wants the source
 * URL and dimensions, the content and excerpt want the description, the author
 * filter wants the creator. That is four decodes of the same text per result,
 * and the answer cannot change in between.
 *
 * The group is registered non-persistent, so on a site running a Redis object
 * cache — as Margaret's does — this stays in process memory instead of writing
 * a second, independently staleable copy of data postmeta already holds.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function isg_mirror_read_row( $post_id ) {
	$post_id = (int) $post_id;

	$found = false;
	$row   = wp_cache_get( $post_id, ISG_ROW_CACHE_GROUP, false, $found );
	if ( $found ) {
		return $row;
	}

	$row  = null;
	$json = get_post_meta( $post_id, ISG_META_ROW, true );
	if ( is_string( $json ) && '' !== $json ) {
		$decoded = json_decode( $json, true );
		if ( is_array( $decoded ) && isset( $decoded['image'], $decoded['page'], $decoded['triples'] ) ) {
			$row = $decoded;
		}
	}

	// null is cached too: a post with no readable row is asked the same four
	// questions as any other, and should not re-read meta for each of them.
	wp_cache_set( $post_id, $row, ISG_ROW_CACHE_GROUP );
	return $row;
}

/**
 * Forget a memoised row. Called wherever one is written, so a sync that writes
 * and then renders in the same request — which is exactly what the first view
 * of a never-synced gallery does — cannot serve what it has just replaced.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function isg_mirror_forget_row( $post_id ) {
	wp_cache_delete( (int) $post_id, ISG_ROW_CACHE_GROUP );
}

/**
 * Whether a graph IRI is safe to interpolate into a SPARQL VALUES clause.
 *
 * @param string $iri IRI.
 * @return bool
 */
function isg_iri_is_clean( $iri ) {
	return is_string( $iri ) && '' !== $iri && ! preg_match( '/[\s<>"{}|\\\\^`]/', $iri );
}

/**
 * Pull the whole gallery from the endpoint: every image, every graph.
 *
 * Two queries rather than one. The list query is small and unbounded by
 * anything but the ceiling; the graph query is the heavy one and runs in
 * batches, so a large gallery is many bounded requests rather than one that
 * times out. Nothing is written here — the caller only proceeds if every
 * request succeeded, so a half-fetched gallery can never reach the database.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @param int    $timeout  Per-request timeout in seconds.
 * @return array|WP_Error  Rows, or WP_Error.
 */
function isg_sync_fetch_gallery( $endpoint, $gallery, $timeout = 20 ) {
	$listing = isg_sparql_json( $endpoint, isg_build_sparql_list( $gallery ), $timeout );
	if ( is_wp_error( $listing ) ) {
		return $listing;
	}

	$meta = array();
	foreach ( $listing as $b ) {
		if ( ! isset( $b['page']['value'], $b['image']['value'] ) || ! isg_iri_is_clean( $b['page']['value'] ) ) {
			continue;
		}
		$meta[ $b['page']['value'] ] = array(
			'image' => $b['image']['value'],
			'date'  => isset( $b['date_']['value'] ) ? $b['date_']['value'] : '',
			'title' => isset( $b['title_']['value'] ) ? $b['title_']['value'] : '',
		);
	}

	/**
	 * Filters the listed graph IRIs before their graphs are fetched.
	 *
	 * @param array  $meta     Map of graph IRI to image/date/title.
	 * @param string $endpoint SPARQL endpoint URL.
	 * @param string $gallery  Gallery name.
	 */
	$meta = apply_filters( 'isg_sync_image_list', $meta, $endpoint, $gallery );

	if ( empty( $meta ) ) {
		return array();
	}

	$bindings = array();
	$batch    = max( 1, (int) apply_filters( 'isg_sync_batch_size', ISG_SYNC_BATCH ) );
	foreach ( array_chunk( array_keys( $meta ), $batch ) as $pages ) {
		$part = isg_sparql_json( $endpoint, isg_build_sparql_graphs( $pages ), $timeout );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		foreach ( $part as $b ) {
			$bindings[] = $b;
		}
	}

	return isg_parse_graph_bindings( $bindings, $meta );
}

/**
 * Synchronise one gallery: fetch, diff, write, detach, delete orphans, purge.
 *
 * The rules that make this safe to run unattended:
 *
 *   - Any fetch error changes nothing.
 *   - An empty result for a gallery that previously had images changes nothing
 *     unless a person forced it. Cron must never empty a gallery on the word of
 *     one bad response; a person clicking Refresh is asserting it really is empty.
 *   - Rows are stored before anything is purged (refresh-then-purge).
 *   - Purge only when something changed, or when a person asked.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @param array  $opts     {
 *     @type bool $force   A person asked: accept an empty result and always purge.
 *     @type int  $timeout Per-request timeout in seconds.
 * }
 * @return array|WP_Error Summary: images, added, updated, removed, changed, purged.
 */
function isg_sync_gallery( $endpoint, $gallery, array $opts = array() ) {
	$gallery = trim( (string) $gallery );
	$force   = ! empty( $opts['force'] );
	$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 20;

	if ( '' === $gallery ) {
		return new WP_Error( 'isg_no_gallery', __( 'No gallery name.', 'image-snippets-gallery' ) );
	}

	$rows = isg_sync_fetch_gallery( $endpoint, $gallery, $timeout );
	if ( is_wp_error( $rows ) ) {
		isg_record_sync( $gallery, array( 'error' => $rows->get_error_message() ) );
		return $rows;
	}

	// Only now create the label: a gallery that could not be fetched leaves
	// nothing behind.
	$term = isg_gallery_term( $endpoint, $gallery, true );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'isg_term', __( 'Could not create the gallery label.', 'image-snippets-gallery' ) );
	}

	$before = array_map( 'intval', (array) get_objects_in_term( $term->term_id, ISG_TAXONOMY ) );

	if ( empty( $rows ) && ! empty( $before ) && ! $force ) {
		$error = new WP_Error(
			'isg_empty',
			sprintf(
				/* translators: %d: number of images previously mirrored */
				__( 'ImageSnippets returned no images for a gallery that had %d; keeping the stored copy.', 'image-snippets-gallery' ),
				count( $before )
			)
		);
		isg_record_sync( $gallery, array( 'error' => $error->get_error_message() ) );
		return $error;
	}

	$existing = isg_mirror_posts_for_pages( wp_list_pluck( $rows, 'page' ) );

	$added   = 0;
	$updated = 0;
	$kept    = array();

	foreach ( $rows as $row ) {
		$post_id = isset( $existing[ $row['page'] ] ) ? (int) $existing[ $row['page'] ] : 0;

		// An unchanged row is kept as is — unless its post is not published,
		// which an earlier version could cause with a future post date. Rewriting
		// republishes it.
		if ( $post_id && 'publish' === get_post_status( $post_id ) && get_post_meta( $post_id, ISG_META_HASH, true ) === isg_row_hash( $row ) ) {
			$kept[] = $post_id;
		} else {
			$written = isg_mirror_write_row( $row, $post_id ? $post_id : null );
			if ( is_wp_error( $written ) ) {
				isg_record_sync( $gallery, array( 'error' => $written->get_error_message() ) );
				return $written;
			}
			if ( $post_id ) {
				++$updated;
			} else {
				++$added;
			}
			$kept[] = (int) $written;
		}
	}

	// Attach the label. Appending is idempotent, and only images not already
	// carrying it count as a change.
	$attached = 0;
	foreach ( $kept as $post_id ) {
		if ( ! in_array( $post_id, $before, true ) ) {
			wp_set_object_terms( $post_id, array( (int) $term->term_id ), ISG_TAXONOMY, true );
			++$attached;
		}
	}

	// Detach whatever left the gallery, and delete anything left with no
	// gallery at all. Hard delete: there is no trash for a thing the admin
	// cannot see.
	$removed = 0;
	foreach ( array_diff( $before, $kept ) as $post_id ) {
		wp_remove_object_terms( $post_id, array( (int) $term->term_id ), ISG_TAXONOMY );
		++$removed;
		$left = wp_get_object_terms( $post_id, ISG_TAXONOMY, array( 'fields' => 'ids' ) );
		if ( empty( $left ) || is_wp_error( $left ) ) {
			wp_delete_post( $post_id, true );
		}
	}

	$changed = ( $added + $updated + $removed + $attached ) > 0;

	update_term_meta( $term->term_id, ISG_TERM_SYNCED, time() );
	delete_transient( isg_lock_key( $endpoint, $gallery ) );
	// The taxonomy count is what the Tools screen shows; make sure it is current.
	wp_update_term_count_now( array( (int) $term->term_id ), ISG_TAXONOMY );

	isg_record_sync(
		$gallery,
		array(
			'last_sync' => time(),
			'rows'      => count( $rows ),
			'error'     => '',
			'changed'   => $changed,
		)
	);

	$purged = array();
	if ( $changed || $force ) {
		$purged = isg_purge_page_cache( isg_posts_for_gallery( $gallery ) );
	}

	/**
	 * Fires after a gallery has been synchronised into the mirror.
	 *
	 * @param string $gallery  Gallery name.
	 * @param string $endpoint SPARQL endpoint URL.
	 * @param array  $summary  Counts.
	 */
	$summary = array(
		'images'  => count( $rows ),
		'added'   => $added,
		'updated' => $updated,
		'removed' => $removed,
		'changed' => $changed,
		'purged'  => $purged,
	);
	do_action( 'isg_gallery_synced', $gallery, $endpoint, $summary );

	return $summary;
}
add_action( 'isg_sync_gallery', 'isg_cron_sync_gallery', 10, 2 );

/**
 * Cron entry point. Long timeouts are fine here; nobody is waiting.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return void
 */
function isg_cron_sync_gallery( $endpoint, $gallery ) {
	isg_sync_gallery( $endpoint, $gallery, array( 'timeout' => 20 ) );
}

/**
 * Remove a whole gallery from the mirror: detach every image, delete orphans,
 * drop the label. Used when no page on the site references the gallery any
 * more, and on uninstall.
 *
 * @param WP_Term $term Gallery term.
 * @return int Images that were detached.
 */
function isg_mirror_drop_gallery( WP_Term $term ) {
	$posts = array_map( 'intval', (array) get_objects_in_term( $term->term_id, ISG_TAXONOMY ) );
	foreach ( $posts as $post_id ) {
		wp_remove_object_terms( $post_id, array( (int) $term->term_id ), ISG_TAXONOMY );
		$left = wp_get_object_terms( $post_id, ISG_TAXONOMY, array( 'fields' => 'ids' ) );
		if ( empty( $left ) || is_wp_error( $left ) ) {
			wp_delete_post( $post_id, true );
		}
	}
	wp_delete_term( $term->term_id, ISG_TAXONOMY );
	return count( $posts );
}

/**
 * Drop mirrored galleries that no post on the site references any more.
 *
 * Runs after the block index changes, so removing the last block for a gallery
 * cleans up behind it without anyone having to know there was anything to clean.
 *
 * "References" means a live post: isg_galleries_in_use() rather than
 * isg_indexed_galleries(), so a page sitting in the trash no longer keeps a
 * gallery's images mirrored and searchable with nowhere on the site to land.
 *
 * @return array Gallery names dropped.
 */
function isg_prune_mirror() {
	$in_use  = array_fill_keys( isg_galleries_in_use(), true );
	$terms   = get_terms(
		array(
			'taxonomy'   => ISG_TAXONOMY,
			'hide_empty' => false,
		)
	);
	$dropped = array();

	if ( is_wp_error( $terms ) ) {
		return $dropped;
	}
	foreach ( $terms as $term ) {
		$gallery = (string) get_term_meta( $term->term_id, ISG_TERM_GALLERY, true );
		if ( '' === $gallery ) {
			$gallery = $term->name;
		}
		if ( isset( $in_use[ $gallery ] ) ) {
			continue;
		}
		isg_mirror_drop_gallery( $term );
		$dropped[] = $gallery;
	}
	return $dropped;
}

/**
 * Empty the mirror completely. Uninstall, and the Tools screen's last resort.
 *
 * @return int Posts deleted.
 */
function isg_mirror_drop_all() {
	global $wpdb;

	// Direct query on purpose: bulk teardown; wp_delete_post() below does the cache invalidation.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", ISG_POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	$terms = get_terms(
		array(
			'taxonomy'   => ISG_TAXONOMY,
			'hide_empty' => false,
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, ISG_TAXONOMY );
		}
	}
	return count( (array) $ids );
}

/**
 * Read a gallery's rows from the mirror, ordered and limited as the block asks.
 *
 * @param WP_Term $term Gallery term.
 * @param array   $a    Resolved block attributes.
 * @return array Rows.
 */
function isg_mirror_query_rows( WP_Term $term, array $a ) {
	$by_title  = ( 'title' === strtolower( (string) $a['orderBy'] ) );
	$ascending = ( 'asc' === strtolower( (string) $a['order'] ) );
	$limit     = max( 1, min( 200, (int) $a['limit'] ) );

	$args = array(
		'post_type'              => ISG_POST_TYPE,
		'post_status'            => 'publish',
		'posts_per_page'         => $limit,
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => false,
		'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => ISG_TAXONOMY,
				'field'    => 'term_id',
				'terms'    => (int) $term->term_id,
			),
		),
		'meta_key'               => $by_title ? ISG_META_TITLE : ISG_META_DATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'                => array(
			'meta_value' => $ascending ? 'ASC' : 'DESC', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded to one gallery's own mirror posts.
			'ID'         => 'ASC',
		),
	);

	$user_id = isg_sanitize_iri_segment( $a['userId'] );
	if ( '' !== $user_id ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			array(
				'key'     => $by_title ? ISG_META_TITLE : ISG_META_DATE,
				'compare' => 'EXISTS',
			),
			array(
				'key'   => ISG_META_CREATOR,
				'value' => ISG_USER_BASE . $user_id,
			),
		);
	}

	$query = new WP_Query( $args );
	$rows  = array();
	foreach ( $query->posts as $post ) {
		$row = isg_mirror_read_row( $post->ID );
		if ( null !== $row ) {
			$row['_post_id'] = (int) $post->ID;
			$rows[]          = $row;
		}
	}
	return $rows;
}

/**
 * Keep mirror posts out of any query that is not the front-end search or our
 * own read path. Feeds and REST are already excluded by the registration flags;
 * this covers themes and plugins that query 'any' post type for other reasons.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function isg_scope_mirror_queries( WP_Query $query ) {
	if ( $query->is_search() && ! is_admin() ) {
		return;
	}
	$type = $query->get( 'post_type' );
	if ( 'any' === $type ) {
		$types = array_values( array_diff( get_post_types( array( 'exclude_from_search' => false ) ), array( ISG_POST_TYPE ) ) );
		$query->set( 'post_type', $types );
	}
}
add_action( 'pre_get_posts', 'isg_scope_mirror_queries' );

/**
 * Whether a post is one of ours. The block index skips these on save_post:
 * they never contain blocks and there can be thousands of them.
 *
 * @param mixed $post Post.
 * @return bool
 */
function isg_is_mirror_post( $post ) {
	return $post instanceof WP_Post && ISG_POST_TYPE === $post->post_type;
}
