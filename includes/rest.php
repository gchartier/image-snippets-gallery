<?php
/**
 * REST routes behind the editor: the gallery picker's list and the "Refresh from ImageSnippets" button.
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
		'/galleries',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'isg_rest_galleries',
			'permission_callback' => 'isg_rest_can_refresh',
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
 * The galleries the endpoint offers, for the editor's gallery picker.
 *
 * Same permission as refresh: it makes an outbound request on the caller's
 * behalf. Read-only otherwise.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function isg_rest_galleries( WP_REST_Request $request ) {
	$endpoint = (string) $request->get_param( 'endpoint' );
	if ( '' === $endpoint ) {
		$endpoint = isg_default_endpoint();
	}

	$list = isg_list_galleries( $endpoint, (bool) $request->get_param( 'fresh' ) );
	if ( is_wp_error( $list ) ) {
		return new WP_Error(
			'isg_list_failed',
			sprintf(
				/* translators: %s: error message from the SPARQL endpoint */
				__( 'ImageSnippets did not respond: %s', 'image-snippets-gallery' ),
				$list->get_error_message()
			),
			array( 'status' => 502 )
		);
	}

	return rest_ensure_response( array( 'galleries' => $list ) );
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
function isg_rest_refresh( WP_REST_Request $request ) {
	$a = isg_resolve_attributes( (array) $request->get_param( 'attributes' ) );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return new WP_Error(
			'isg_no_gallery',
			__( 'Set a gallery name before refreshing.', 'image-snippets-gallery' ),
			array( 'status' => 400 )
		);
	}

	$result = isg_refresh_gallery( isg_resolve_endpoint( $a ), $a['gallery'] );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'isg_refresh_failed',
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
