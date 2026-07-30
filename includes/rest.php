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
 * Drop the cached rows for one block's query.
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

	isg_purge_cache( isg_resolve_endpoint( $a ), isg_build_sparql( $a ) );

	return rest_ensure_response( array( 'purged' => true ) );
}
