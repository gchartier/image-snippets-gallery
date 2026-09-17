<?php
/**
 * REST routes behind the editor and the Tools screen: the gallery picker's
 * list, the "Refresh from ImageSnippets" button, and the reorder screen.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the refresh route.
 *
 * @return void
 */
function isgal_register_rest_routes() {
	register_rest_route(
		'imagesnippets/v1',
		'/galleries',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'isgal_rest_galleries',
			'permission_callback' => 'isgal_rest_can_refresh',
			'args'                => array(
				'endpoint' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
				'fresh'    => array(
					'type'     => 'boolean',
					'required' => false,
					'default'  => false,
				),
			),
		)
	);
	register_rest_route(
		'imagesnippets/v1',
		'/refresh',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'isgal_rest_refresh',
			'permission_callback' => 'isgal_rest_can_refresh',
			'args'                => array(
				'attributes' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
	register_rest_route(
		'imagesnippets/v1',
		'/order',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'isgal_rest_order_get',
				'permission_callback' => 'isgal_rest_can_refresh',
				'args'                => isgal_rest_order_args(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'isgal_rest_order_set',
				'permission_callback' => 'isgal_rest_can_refresh',
				'args'                => array_merge(
					isgal_rest_order_args(),
					array(
						'pages' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					)
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'isgal_register_rest_routes' );

/**
 * The gallery name a term stands for, as the blocks spell it.
 *
 * @param WP_Term $term Gallery term.
 * @return string
 */
function isgal_term_gallery_name( WP_Term $term ) {
	$name = (string) get_term_meta( $term->term_id, ISGAL_TERM_GALLERY, true );
	return '' !== $name ? $name : (string) $term->name;
}

/**
 * Shared arguments of the order routes.
 *
 * @return array
 */
function isgal_rest_order_args() {
	return array(
		'gallery'  => array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		'endpoint' => array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'esc_url_raw',
		),
	);
}

/**
 * The gallery term an order request refers to, syncing first if it has never
 * been mirrored so the reorder screen is never empty for a fresh gallery.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_Term|WP_Error
 */
function isgal_rest_order_term( WP_REST_Request $request ) {
	$gallery  = trim( (string) $request->get_param( 'gallery' ) );
	$endpoint = (string) $request->get_param( 'endpoint' );
	if ( '' === $endpoint ) {
		$endpoint = isgal_default_endpoint();
	}
	if ( '' === $gallery ) {
		return new WP_Error( 'isgal_no_gallery', __( 'No gallery name.', 'image-snippets-gallery' ), array( 'status' => 400 ) );
	}
	$term = isgal_gallery_term( $endpoint, $gallery );
	if ( ! $term instanceof WP_Term ) {
		$result = isgal_sync_gallery( $endpoint, $gallery, array( 'timeout' => 20 ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		$term = isgal_gallery_term( $endpoint, $gallery );
	}
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'isgal_no_mirror', __( 'Gallery has not been synchronised yet.', 'image-snippets-gallery' ), array( 'status' => 404 ) );
	}
	return $term;
}

/**
 * Every image in a gallery, in its current effective manual order: arranged
 * images first, then the unarranged ones newest-first. Feeds the reorder screen.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isgal_rest_order_get( WP_REST_Request $request ) {
	$term = isgal_rest_order_term( $request );
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$order = isgal_gallery_manual_order( $term );
	$rows  = isgal_mirror_query_rows(
		$term,
		isgal_resolve_attributes(
			array(
				'gallery' => isgal_term_gallery_name( $term ),
				'orderBy' => 'manual',
				'limit'   => 200,
			)
		)
	);
	$rank  = array_flip( $order );

	$items = array();
	foreach ( $rows as $row ) {
		$source = $row['content'];
		if ( '' === $source && preg_match( '#^https?://#i', $row['image'] ) ) {
			$source = $row['image'];
		}
		$items[] = array(
			'page'     => $row['page'],
			'title'    => isgal_row_title( $row, true ),
			'thumb'    => '' !== $source ? isgal_flickr_sized( $source, 'n' ) : $row['thumb'],
			'fallback' => $row['thumb'],
			'arranged' => isset( $rank[ $row['page'] ] ),
		);
	}

	return rest_ensure_response(
		array(
			'gallery'  => isgal_term_gallery_name( $term ),
			'arranged' => ! empty( $order ),
			'items'    => $items,
		)
	);
}

/**
 * Store a gallery's arranged order and invalidate the pages that show it.
 *
 * An empty list clears the arrangement. Unknown IRIs are dropped rather than
 * rejected: the gallery may have changed under the person's feet.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isgal_rest_order_set( WP_REST_Request $request ) {
	$term = isgal_rest_order_term( $request );
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$pages   = array_map( 'strval', (array) $request->get_param( 'pages' ) );
	$known   = isgal_mirror_posts_for_pages( $pages );
	$pages   = array_values( array_filter( $pages, static fn( $p ) => isset( $known[ $p ] ) ) );
	$members = array_flip( array_map( 'intval', (array) get_objects_in_term( $term->term_id, ISGAL_TAXONOMY ) ) );
	$pages   = array_values( array_filter( $pages, static fn( $p ) => isset( $members[ (int) $known[ $p ] ] ) ) );

	isgal_set_gallery_manual_order( $term, $pages );

	$purged = isgal_purge_page_cache( isgal_posts_for_gallery( isgal_term_gallery_name( $term ) ) );

	return rest_ensure_response(
		array(
			'saved'    => true,
			'arranged' => count( $pages ),
			'purged'   => array_values( $purged ),
		)
	);
}

/**
 * Only editors may force a fresh pull — the route triggers an outbound request
 * to the SPARQL endpoint, so it must not be open to anonymous callers.
 *
 * @return bool
 */
function isgal_rest_can_refresh() {
	return current_user_can( 'edit_posts' );
}

/**
 * The galleries the endpoint offers, for the editor's gallery picker.
 *
 * Same permission as refresh: it makes an outbound request on the caller's
 * behalf. Read-only otherwise.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isgal_rest_galleries( WP_REST_Request $request ) {
	$endpoint = (string) $request->get_param( 'endpoint' );
	if ( '' === $endpoint ) {
		$endpoint = isgal_default_endpoint();
	}

	$list = isgal_list_galleries( $endpoint, (bool) $request->get_param( 'fresh' ) );
	if ( is_wp_error( $list ) ) {
		return new WP_Error(
			'isgal_list_failed',
			sprintf(
				/* translators: %s: error message from the SPARQL endpoint */
				__( 'ImageSnippets did not respond: %s', 'image-snippets-gallery' ),
				$list->get_error_message()
			),
			array( 'status' => 502 )
		);
	}

	// Saved sources first: there are few of them, and somebody here wrote them.
	// They live on the site's default endpoint, so a block overriding it is not offered them.
	$sources = array();
	if ( isgal_default_endpoint() === $endpoint ) {
		foreach ( isgal_list_sources() as $source ) {
			$sources[] = array(
				'value' => $source['name'],
				/* translators: %s: the name of a saved source */
				'label' => sprintf( __( '%s (saved source)', 'image-snippets-gallery' ), $source['label'] ),
				'count' => $source['images'],
			);
		}
	}

	return rest_ensure_response( array( 'galleries' => array_merge( $sources, $list ) ) );
}

/**
 * Sync one block's gallery again and invalidate the pages that show it.
 *
 * Deliberately a sync, not just a purge. Dropping the mirror and leaving the
 * refetch to whoever visits next would leave a page cache holding the old HTML
 * with nothing scheduled to replace it, so the button would appear to do
 * nothing. Fetch first, store, then invalidate.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isgal_rest_refresh( WP_REST_Request $request ) {
	$a = isgal_resolve_attributes( (array) $request->get_param( 'attributes' ) );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return new WP_Error(
			'isgal_no_gallery',
			__( 'Set a gallery name before refreshing.', 'image-snippets-gallery' ),
			array( 'status' => 400 )
		);
	}

	$result = isgal_refresh_gallery( isgal_resolve_endpoint( $a ), $a['gallery'] );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'isgal_refresh_failed',
			sprintf(
				/* translators: %s: error message from the SPARQL endpoint */
				__( 'ImageSnippets did not respond: %s', 'image-snippets-gallery' ),
				$result->get_error_message()
			),
			array( 'status' => 502 )
		);
	}

	return rest_ensure_response(
		array(
			'refreshed' => true,
			'images'    => (int) $result['images'],
			'added'     => (int) $result['added'],
			'updated'   => (int) $result['updated'],
			'removed'   => (int) $result['removed'],
		)
	);
}
