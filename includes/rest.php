<?php
/**
 * REST route backing the editor's "Refresh from ImageSnippets" button.
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
function isg_register_rest_routes() {
	register_rest_route(
		'imagesnippets/v1',
		'/refresh',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'isg_rest_refresh',
			'permission_callback' => 'isg_rest_can_refresh',
			'args'                => array(
				'attributes' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'isg_register_rest_routes' );

/**
 * Only editors may force a fresh pull — the route triggers an outbound request
 * to the SPARQL endpoint, so it must not be open to anonymous callers.
 *
 * @return bool
 */
function isg_rest_can_refresh() {
	return current_user_can( 'edit_posts' );
}

/**
 * Pull one block's gallery again and invalidate the pages that show it.
 *
 * Deliberately a refresh, not just a purge. Dropping the cache and leaving the
 * refetch to whoever visits next would leave a page cache holding the old HTML
 * with nothing scheduled to replace it, so the button would appear to do
 * nothing. Fetch first, store, then invalidate.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isg_rest_refresh( WP_REST_Request $request ) {
	$a = isg_resolve_attributes( (array) $request->get_param( 'attributes' ) );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return new WP_Error(
			'isg_no_gallery',
			__( 'Set a gallery name before refreshing.', 'image-snippets-gallery' ),
			array( 'status' => 400 )
		);
	}

	$endpoint = isg_resolve_endpoint( $a );
	$query    = isg_build_sparql( $a );

	// Clear the editor's own short-lived copy so the preview re-renders from the
	// new rows, and the lock so this refresh is never skipped. The public entry
	// stays put: isg_do_refresh_cache compares against it to decide whether
	// anything actually changed.
	delete_transient( isg_cache_key( $endpoint, $query, 'editor' ) );
	delete_transient( isg_lock_key( $endpoint, $query ) );

	// The configured lifetime, not the editor-capped one — this writes the entry
	// the public page will read.
	$rows = isg_do_refresh_cache( $endpoint, $query, isg_configured_ttl( $a ), $a['gallery'], true );

	if ( is_wp_error( $rows ) ) {
		return new WP_Error(
			'isg_refresh_failed',
			sprintf(
				/* translators: %s: error message from the SPARQL endpoint */
				__( 'ImageSnippets did not respond: %s', 'image-snippets-gallery' ),
				$rows->get_error_message()
			),
			array( 'status' => 502 )
		);
	}

	return rest_ensure_response(
		array(
			'refreshed' => true,
			'images'    => count( $rows ),
		)
	);
}
