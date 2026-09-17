<?php
/**
 * The share card of a page that shows a gallery.
 *
 * WordPress core emits no Open Graph tags, and the plugins that do look for a
 * page's image in its featured image, in the markup saved in post_content and
 * among its attachments. A gallery is none of those: the block is rendered at
 * request time from the mirror, so post_content holds a comment, and the
 * images live on ImageSnippets, not in the media library. Left alone, a shared
 * gallery page is a card with no picture — with or without an SEO plugin.
 *
 * So the gallery's first image, under the block's own sort, stands as the
 * page's share image. Where an SEO plugin is writing the tags it is handed the
 * image through that plugin's own hook and writes them as it sees fit; where
 * none is, a minimal set is written here. A featured image, or an image chosen
 * in the SEO plugin, is the author's word and always wins.
 *
 * Read from the mirror only: nothing here ever asks ImageSnippets anything.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attributes of the first gallery block among these blocks, depth first, that
 * names a gallery.
 *
 * @param array $blocks Parsed blocks.
 * @return array|null Raw block attributes.
 */
function isgal_first_gallery_block( array $blocks ) {
	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) && 'imagesnippets/gallery' === $block['blockName'] ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( isset( $attrs['gallery'] ) && '' !== trim( (string) $attrs['gallery'] ) ) {
				return $attrs;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = isgal_first_gallery_block( $block['innerBlocks'] );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * The image that stands for a page when it is shared: the first image of the
 * first gallery on it.
 *
 * Width and height are given only when the graph states them for the very URL
 * returned. A Flickr original is asked for at 1024px, whose dimensions the
 * graph does not state, so there they are left out rather than guessed.
 *
 * @param int $post_id Post ID.
 * @return array|null [ 'url', 'alt', 'width', 'height' ] (width/height 0 when unknown).
 */
function isgal_page_share_image( $post_id ) {
	static $memo = array();

	$post_id = absint( $post_id );
	if ( isset( $memo[ $post_id ] ) ) {
		return false === $memo[ $post_id ] ? null : $memo[ $post_id ];
	}
	$memo[ $post_id ] = false;

	$post = get_post( $post_id );
	// The index says which posts show a gallery at all, so most never reach the parse.
	if ( ! $post instanceof WP_Post || ! get_post_meta( $post_id, ISGAL_GALLERY_META, false ) || ! has_blocks( $post->post_content ) ) {
		return null;
	}

	$attrs = isgal_first_gallery_block( parse_blocks( $post->post_content ) );
	if ( null === $attrs ) {
		return null;
	}

	$a    = isgal_resolve_attributes( $attrs );
	$term = isgal_gallery_term( isgal_resolve_endpoint( $a ), $a['gallery'] );
	if ( ! $term instanceof WP_Term ) {
		return null;
	}

	$rows = isgal_mirror_query_rows( $term, $a );
	if ( empty( $rows ) ) {
		return null;
	}
	$row = reset( $rows );

	$source = $row['content'];
	if ( '' === $source && preg_match( '#^https?://#i', $row['image'] ) ) {
		$source = $row['image'];
	}
	if ( '' === $source ) {
		return null;
	}

	$url   = isgal_flickr_sized( $source, 'b' );
	$dims  = $url === $source ? isgal_row_dimensions( $row ) : null;
	$image = array(
		'url'    => esc_url_raw( $url ),
		'alt'    => isgal_row_alt( $row, (string) $a['altSource'] ),
		'width'  => $dims ? (int) $dims[0] : 0,
		'height' => $dims ? (int) $dims[1] : 0,
	);

	/**
	 * Filter the image a page showing a gallery is shared with.
	 *
	 * @param array|null $image   [ 'url', 'alt', 'width', 'height' ], or null for none.
	 * @param int        $post_id Post ID.
	 * @param array      $row     The mirrored row the image came from.
	 */
	$image = apply_filters( 'isgal_page_share_image', $image, $post_id, $row );
	if ( ! is_array( $image ) || empty( $image['url'] ) ) {
		return null;
	}

	$memo[ $post_id ] = $image;
	return $image;
}

/**
 * Which plugin, if any, is writing this site's Open Graph tags.
 *
 * @return string A name to show a person, or '' when nobody is.
 */
function isgal_open_graph_writer() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'Yoast SEO';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'Rank Math';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'SEOPress';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'All in One SEO';
	}
	if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
		return 'The SEO Framework';
	}
	if ( defined( 'SLIM_SEO_VER' ) ) {
		return 'Slim SEO';
	}
	// Jetpack loads its Open Graph functions only when it means to write the tags.
	if ( function_exists( 'jetpack_og_tags' ) ) {
		return 'Jetpack';
	}
	return '';
}

/**
 * The share image of the page being shown, for the SEO plugin hooks below.
 *
 * @return array|null
 */
function isgal_queried_share_image() {
	if ( ! is_singular() ) {
		return null;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id || has_post_thumbnail( $post_id ) ) {
		return null;
	}
	return isgal_page_share_image( $post_id );
}

/**
 * Yoast SEO: offered after Yoast has looked at its own social image and the
 * featured image, before it falls back to the site default.
 *
 * @param object $container Yoast\WP\SEO\Values\Open_Graph\Images.
 */
function isgal_yoast_share_image( $container ) {
	if ( ! is_object( $container ) || ! method_exists( $container, 'add_image' ) || ! method_exists( $container, 'has_images' ) || $container->has_images() ) {
		return;
	}
	$image = isgal_queried_share_image();
	if ( null === $image ) {
		return;
	}
	$entry = array(
		'url' => $image['url'],
		'alt' => $image['alt'],
	);
	if ( $image['width'] && $image['height'] ) {
		$entry['width']  = $image['width'];
		$entry['height'] = $image['height'];
	}
	$container->add_image( $entry );
}
add_filter( 'wpseo_add_opengraph_additional_images', 'isgal_yoast_share_image' );

/**
 * Rank Math: the same place in its order, once for each network it writes.
 *
 * @param object $images RankMath\OpenGraph\Image.
 */
function isgal_rank_math_share_image( $images ) {
	if ( ! is_object( $images ) || ! method_exists( $images, 'add_image' ) || ! method_exists( $images, 'has_images' ) || $images->has_images() ) {
		return;
	}
	$image = isgal_queried_share_image();
	if ( null !== $image ) {
		$images->add_image( $image['url'] );
	}
}
add_action( 'rank_math/opengraph/facebook/add_additional_images', 'isgal_rank_math_share_image' );
add_action( 'rank_math/opengraph/twitter/add_additional_images', 'isgal_rank_math_share_image' );

/**
 * With nobody else writing them, write the tags a share card is built from.
 */
function isgal_render_open_graph() {
	if ( ! is_singular() ) {
		return;
	}
	$post_id = get_queried_object_id();
	$image   = $post_id ? isgal_page_share_image( $post_id ) : null;

	/**
	 * Filter whether this plugin writes Open Graph tags for a page showing a
	 * gallery. Off by default where an SEO plugin is writing them.
	 *
	 * @param bool $emit    Whether to write the tags.
	 * @param int  $post_id Post ID.
	 */
	if ( null === $image || ! apply_filters( 'isgal_emit_open_graph', '' === isgal_open_graph_writer(), $post_id ) ) {
		return;
	}

	// A featured image is the author's choice of picture for this page.
	if ( has_post_thumbnail( $post_id ) ) {
		$featured = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		if ( $featured ) {
			$image = array(
				'url'    => $featured[0],
				'alt'    => (string) get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ),
				'width'  => (int) $featured[1],
				'height' => (int) $featured[2],
			);
		}
	}

	$tags = array(
		'og:type'      => 'article',
		'og:title'     => wp_strip_all_tags( get_the_title( $post_id ) ),
		'og:url'       => get_permalink( $post_id ),
		'og:site_name' => get_bloginfo( 'name' ),
	);
	// Only a description somebody wrote: one made from a page that is all
	// gallery would be made of nothing.
	if ( has_excerpt( $post_id ) ) {
		$tags['og:description'] = wp_strip_all_tags( get_the_excerpt( $post_id ) );
	}
	$tags['og:image'] = $image['url'];
	if ( $image['width'] && $image['height'] ) {
		$tags['og:image:width']  = (string) $image['width'];
		$tags['og:image:height'] = (string) $image['height'];
	}
	if ( '' !== $image['alt'] ) {
		$tags['og:image:alt'] = $image['alt'];
	}

	echo "\n<!-- ImageSnippets Gallery: Open Graph -->\n";
	foreach ( $tags as $property => $content ) {
		if ( '' !== (string) $content ) {
			printf( "<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $property ), esc_attr( $content ) );
		}
	}
	echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";
}
add_action( 'wp_head', 'isgal_render_open_graph', 5 );
