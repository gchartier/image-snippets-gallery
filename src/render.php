<?php
/**
 * Server-side render for the ImageSnippets Gallery block.
 *
 * WordPress provides $attributes, $content, and $block in scope.
 * The heavy lifting (SPARQL + caching + JSON-LD) lives in includes/query.php.
 *
 * @package ImageSnippetsGallery
 */

if ( ! function_exists( 'isg_render_gallery' ) ) {
	return;
}

echo isg_render_gallery( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within renderer.
