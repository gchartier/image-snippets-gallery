<?php
/**
 * Block patterns: ready-made gallery blocks under an "ImageSnippets" category
 * in the inserter's Patterns tab. Each is one gallery block with the settings
 * that make a particular layout look intended; the gallery itself is left for
 * the editor to pick (the block's Source panel offers the site's galleries).
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The patterns, as attribute sets. Kept as data so the demo-page action on
 * Tools → ImageSnippets can reuse them.
 *
 * @return array Slug => array( title, description, attributes ).
 */
function isgal_pattern_definitions() {
	return array(
		'gallery-with-title' => array(
			'title'       => __( 'Gallery with title', 'image-snippets-gallery' ),
			'description' => __( 'A three-column grid with the gallery name above it and a title under each image.', 'image-snippets-gallery' ),
			'attributes'  => array(
				'displayTitle'   => true,
				'displayCaption' => true,
				'captionFields'  => array( 'title' ),
				'onClick'        => 'lightbox',
			),
		),
		'portfolio-grid'     => array(
			'title'       => __( 'Portfolio grid', 'image-snippets-gallery' ),
			'description' => __( 'Four square tiles a row, captions appearing on hover, opening in a lightbox with the provenance panel.', 'image-snippets-gallery' ),
			'attributes'  => array(
				'columns'         => 4,
				'aspectRatio'     => '1-1',
				'displayCaption'  => true,
				'captionPosition' => 'hover',
				'captionFields'   => array( 'title', 'creator' ),
				'hoverEffect'     => 'zoom',
				'onClick'         => 'lightbox',
				'align'           => 'wide',
			),
		),
		'slideshow-hero'     => array(
			'title'       => __( 'Slideshow hero', 'image-snippets-gallery' ),
			'description' => __( 'One full-width image at a time, changing every six seconds, with dots to jump between them.', 'image-snippets-gallery' ),
			'attributes'  => array(
				'layout'         => 'slideshow',
				'slideAutoplay'  => true,
				'slideInterval'  => 6,
				'slideNav'       => 'dots',
				'aspectRatio'    => '16-9',
				'displayCaption' => true,
				'captionFields'  => array( 'title', 'creator' ),
				'align'          => 'full',
			),
		),
		'timeline'           => array(
			'title'       => __( 'Timeline', 'image-snippets-gallery' ),
			'description' => __( 'Images grouped under year headings, oldest first, each with its date and creator.', 'image-snippets-gallery' ),
			'attributes'  => array(
				'layout'         => 'timeline',
				'order'          => 'asc',
				'columns'        => 2,
				'displayCaption' => true,
				'captionFields'  => array( 'title', 'date', 'creator' ),
				'onClick'        => 'lightbox',
			),
		),
	);
}

/**
 * The serialised block for a pattern (or any attribute set).
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function isgal_block_markup( array $attributes ) {
	return serialize_block(
		array(
			'blockName'    => 'imagesnippets/gallery',
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

/**
 * Create a draft page holding one gallery block, from a pattern's settings.
 *
 * @param string $gallery Gallery name (may be owner/name).
 * @param string $pattern Pattern slug from isgal_pattern_definitions().
 * @return int|WP_Error Post ID.
 */
function isgal_create_demo_page( $gallery, $pattern = 'gallery-with-title' ) {
	$gallery = trim( (string) $gallery );
	if ( '' === $gallery ) {
		return new WP_Error( 'isgal_no_gallery', __( 'Choose a gallery first.', 'image-snippets-gallery' ) );
	}
	$patterns   = isgal_pattern_definitions();
	$attributes = isset( $patterns[ $pattern ] ) ? $patterns[ $pattern ]['attributes'] : array( 'displayTitle' => true );
	$attributes = array_merge( array( 'gallery' => $gallery ), $attributes );
	$content    = isgal_block_markup( $attributes );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => wp_slash( $gallery ),
			'post_content' => wp_slash( $content ),
		),
		true
	);
	return $post_id;
}

/**
 * Register the category and the patterns.
 *
 * @return void
 */
function isgal_register_patterns() {
	if ( ! function_exists( 'register_block_pattern' ) ) {
		return;
	}
	register_block_pattern_category(
		'imagesnippets',
		array(
			'label'       => __( 'ImageSnippets', 'image-snippets-gallery' ),
			'description' => __( 'Galleries of images kept on ImageSnippets, with their provenance.', 'image-snippets-gallery' ),
		)
	);
	foreach ( isgal_pattern_definitions() as $slug => $pattern ) {
		register_block_pattern(
			'imagesnippets/' . $slug,
			array(
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'categories'  => array( 'imagesnippets' ),
				'blockTypes'  => array( 'imagesnippets/gallery' ),
				'keywords'    => array( 'gallery', 'imagesnippets', 'images' ),
				'content'     => isgal_block_markup( $pattern['attributes'] ),
			)
		);
	}
}
add_action( 'init', 'isgal_register_patterns', 20 );
