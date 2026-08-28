<?php
/**
 * How a mirrored image presents itself in search results.
 *
 * Mirror posts are index entries, not pages: no permalink of their own, no
 * attachment, and a post_content full of machine-oriented index text. A theme
 * asks each result the same four questions all the same — what is your title,
 * your link, your image, your text — and renders whatever comes back. Nothing
 * here renders a search result; every function answers one of those questions
 * the way a normal post would, so the theme's own markup and CSS apply with no
 * cooperation from us.
 *
 * The image answer in particular is a contract, not a suggestion.
 * get_the_post_thumbnail() passes the caller's requested $size and $attr
 * through to the post_thumbnail_html filter, and a block or theme uses $attr
 * to say how the image must be laid out — core's Post Featured Image block
 * puts 'width:100%;height:100%;object-fit:cover' there whenever an aspect
 * ratio is set. Ignoring those arguments and returning hand-built markup is
 * what made mirrored images render at their natural size and spill over the
 * result title.
 *
 * @package ImageSnippetsGallery
 */

defined( 'ABSPATH' ) || exit;

/**
 * The page a search hit should land on: the first published page showing one
 * of the image's galleries, else its canonical ImageSnippets page.
 *
 * The gallery-page link carries the image's #fragment, so a hit lands on the
 * image that matched rather than at the top of a grid of hundreds. Without it
 * every image in one gallery resolves to the same bare URL, and a search
 * matching a dozen of them returns a dozen identical-looking results. The
 * fragment is only appended on this site's pages: the ImageSnippets fallback
 * below is another site, which has no such anchor.
 *
 * @param WP_Post $post Mirror post.
 * @return string URL.
 */
function isgal_mirror_post_url( WP_Post $post ) {
	static $by_term = array();

	$page   = (string) get_post_meta( $post->ID, ISGAL_META_PAGE, true );
	$anchor = isgal_image_anchor( $page );

	$terms = wp_get_object_terms( $post->ID, ISGAL_TAXONOMY );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( ! array_key_exists( $term->term_id, $by_term ) ) {
				$gallery = (string) get_term_meta( $term->term_id, ISGAL_TERM_GALLERY, true );
				$pages   = isgal_posts_for_gallery( '' !== $gallery ? $gallery : $term->name );
				$url     = '';
				foreach ( $pages as $page_id ) {
					$url = (string) get_permalink( $page_id );
					if ( '' !== $url ) {
						break;
					}
				}
				$by_term[ $term->term_id ] = $url;
			}
			if ( '' !== $by_term[ $term->term_id ] ) {
				return '' !== $anchor
					? $by_term[ $term->term_id ] . '#' . $anchor
					: $by_term[ $term->term_id ];
			}
		}
	}

	return '' !== $page ? $page : home_url( '/' );
}

/**
 * Point search results at the gallery page rather than at a post type that has
 * no page of its own.
 *
 * @param string  $url  Permalink.
 * @param WP_Post $post Post.
 * @return string
 */
function isgal_filter_mirror_permalink( $url, $post ) {
	if ( $post instanceof WP_Post && ISGAL_POST_TYPE === $post->post_type ) {
		return isgal_mirror_post_url( $post );
	}
	return $url;
}
add_filter( 'post_type_link', 'isgal_filter_mirror_permalink', 10, 2 );

/**
 * Resolve a requested image size to pixels.
 *
 * @param string|int[] $size Registered size name, or array( width, height ).
 * @return array array( width, height, crop ); zero width means "unconstrained".
 */
function isgal_size_dimensions( $size ) {
	if ( is_array( $size ) ) {
		return array(
			isset( $size[0] ) ? (int) $size[0] : 0,
			isset( $size[1] ) ? (int) $size[1] : 0,
			false,
		);
	}
	$registered = wp_get_registered_image_subsizes();
	if ( isset( $registered[ $size ] ) ) {
		return array(
			(int) $registered[ $size ]['width'],
			(int) $registered[ $size ]['height'],
			(bool) $registered[ $size ]['crop'],
		);
	}
	// 'full', or a size this site does not register: ask for the original.
	return array( 0, 0, false );
}

/**
 * Give search results an image, in the markup core would have produced.
 *
 * Mirror posts have no attachment, so wp_get_attachment_image() never runs and
 * everything it normally contributes has to be reproduced here: the
 * attachment-{size}/size-{size}/wp-post-image classes themes hang their CSS
 * on, real width and height so the browser can reserve the right box, a srcset
 * where the source offers renditions, and — the part that actually broke the
 * layout — whatever $attr the caller passed. Caller values win over ours, as
 * they do in core, because the caller knows how its own template is laid out.
 *
 * @param string       $html         Thumbnail HTML; non-empty means someone else answered.
 * @param int          $post_id      Post ID.
 * @param int          $thumbnail_id Attachment ID, always 0 for mirror posts.
 * @param string|int[] $size         Size the caller asked for.
 * @param string|array $attr         Attributes the caller asked for.
 * @return string
 */
function isgal_filter_mirror_thumbnail( $html, $post_id, $thumbnail_id, $size, $attr ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || ISGAL_POST_TYPE !== $post->post_type || '' !== $html ) {
		return $html;
	}
	$row = isgal_mirror_read_row( $post_id );
	if ( null === $row ) {
		return $html;
	}
	$source = isgal_first( array( $row['content'], $row['thumb'] ) );
	if ( '' === $source ) {
		return $html;
	}

	list( $target_w, $target_h, $crop ) = isgal_size_dimensions( $size );

	// Fetch a rendition near the requested width where the source offers them;
	// a non-Flickr URL has only the original, and comes back unchanged.
	$src = isgal_flickr_sized( $source, isgal_flickr_code_for_width( $target_w ) );

	// A cropped size is a box, and the box is what gets rendered. Otherwise
	// scale the true pixel dimensions — from the graph, so they are the image's
	// own, not a guess — into whatever the caller allowed.
	$width  = 0;
	$height = 0;
	if ( $crop && $target_w && $target_h ) {
		$width  = $target_w;
		$height = $target_h;
	} else {
		$dimensions = isgal_row_dimensions( $row );
		if ( null !== $dimensions ) {
			list( $width, $height ) = $target_w || $target_h
				? wp_constrain_dimensions( $dimensions[0], $dimensions[1], $target_w, $target_h )
				: $dimensions;
		}
	}

	$size_class = is_array( $size ) ? implode( 'x', $size ) : (string) $size;
	$attr       = wp_parse_args(
		$attr,
		array(
			'src'      => $src,
			'class'    => "attachment-{$size_class} size-{$size_class}",
			'alt'      => isgal_row_alt( $row ),
			'decoding' => 'async',
		)
	);

	// Appended after the merge, exactly as core's _wp_post_thumbnail_class_filter
	// does, so a caller that supplied its own class still gets the one themes
	// use to recognise a featured image. isgal-search-thumbnail rides along as a
	// stable hook for a site that wants to treat these differently; nothing in
	// this plugin styles it, by design — the theme's own rules should apply.
	$attr['class'] = trim( $attr['class'] . ' wp-post-image isgal-search-thumbnail' );

	if ( $width && ( ! isset( $attr['width'] ) || ! is_numeric( $attr['width'] ) ) ) {
		$attr['width'] = $width;
	}
	if ( $height && ( ! isset( $attr['height'] ) || ! is_numeric( $attr['height'] ) ) ) {
		$attr['height'] = $height;
	}

	// Whether this image is lazy-loaded or fetched eagerly is core's call, not
	// ours: it tracks how far down the page each image is and gives the first
	// one above the fold fetchpriority instead. Hardcoding loading="lazy" here
	// left the top result blank while its (remote) source was still in flight.
	// Needs the width and height above to have been decided already.
	$attr = array_merge(
		$attr,
		wp_get_loading_optimization_attributes( 'img', $attr, 'wp_get_attachment_image' )
	);
	if ( isset( $attr['loading'] ) && ! $attr['loading'] ) {
		unset( $attr['loading'] );
	}

	if ( empty( $attr['srcset'] ) ) {
		$srcset = isgal_flickr_srcset( $source );
		if ( '' !== $srcset ) {
			$attr['srcset'] = $srcset;
			if ( empty( $attr['sizes'] ) && $width ) {
				$attr['sizes'] = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );
			}
		}
	}

	// Built through the tag processor rather than by concatenation: it escapes
	// each value once and correctly, including the ampersands in a srcset that
	// a hand-rolled esc_attr() pass would double-encode.
	$tags = new WP_HTML_Tag_Processor( '<img />' );
	$tags->next_tag();
	foreach ( $attr as $name => $value ) {
		$tags->set_attribute( $name, (string) $value );
	}
	return $tags->get_updated_html();
}
add_filter( 'post_thumbnail_html', 'isgal_filter_mirror_thumbnail', 10, 5 );

/**
 * The text a mirror post shows, as opposed to the text it matches on.
 *
 * post_content holds isgal_row_search_text(): title, description, alt, creator,
 * rights and every entity label and keyword the graph carries, one per line.
 * That is what lets a search for "rafter" find an image whose title never says
 * so, and it belongs in a column WordPress will search. It does not belong on
 * screen — a theme that renders the_content() in its results loop, as Twenty
 * Twenty-Five does, prints the whole list. So the stored text keeps doing its
 * job in the database and this answers with prose instead.
 *
 * @param int|WP_Post $post Post.
 * @return string Plain text, or '' when there is nothing to say.
 */
function isgal_mirror_display_text( $post ) {
	$row = isgal_mirror_read_row( is_object( $post ) ? $post->ID : (int) $post );
	if ( null === $row ) {
		return '';
	}
	return isgal_first( array( $row['desc'], $row['alt'], $row['title'], $row['name'] ) );
}

/**
 * Answer the_content() with prose. Registered ahead of wpautop so the returned
 * text is paragraphed by the same chain that formats any other post.
 *
 * @param string $content Content.
 * @return string
 */
function isgal_filter_mirror_content( $content ) {
	$post = get_post();
	if ( ! $post instanceof WP_Post || ISGAL_POST_TYPE !== $post->post_type ) {
		return $content;
	}
	return isgal_mirror_display_text( $post );
}
add_filter( 'the_content', 'isgal_filter_mirror_content', 9 );

/**
 * Answer the excerpt with the same prose.
 *
 * Without this, an image carrying neither a description nor alt text has an
 * empty post_excerpt, and wp_trim_excerpt() falls back to trimming
 * post_content — the keyword list again, by a different route.
 *
 * @param string  $excerpt Excerpt.
 * @param WP_Post $post    Post.
 * @return string
 */
function isgal_filter_mirror_excerpt( $excerpt, $post = null ) {
	$post = $post instanceof WP_Post ? $post : get_post();
	if ( ! $post instanceof WP_Post || ISGAL_POST_TYPE !== $post->post_type ) {
		return $excerpt;
	}
	$text = isgal_mirror_display_text( $post );
	return '' !== $text ? $text : $excerpt;
}
add_filter( 'get_the_excerpt', 'isgal_filter_mirror_excerpt', 10, 2 );

/**
 * Answer the core/post-data binding for mirror posts.
 *
 * Block themes no longer read the date off the post directly: core/post-date
 * resolves it through the core/post-data binding source, and that source
 * refuses any post which is not publicly viewable
 * (wp-includes/block-bindings/post-data.php:58). Mirror posts are registered
 * public => false on purpose — they must never be reachable at a URL of their
 * own — so the check fires and the block renders nothing at all. Twenty
 * Twenty-Five's search template asks for a date on every result; ours were the
 * only results without one.
 *
 * The gate is right about the post type and wrong about this case: the post is
 * not viewable, but it is deliberately searchable, and a result the theme is
 * already rendering is not an information leak. So the fields are answered here
 * exactly as the source computes them, for our post type only, and only where
 * the source declined. Password protection is not bypassed: nothing here can
 * set a password on a mirror post, so a non-null return would already have
 * short-circuited above.
 *
 * @param mixed    $value          Value the source computed, null when it declined.
 * @param string   $name           Source name.
 * @param array    $source_args    Source arguments; 'field' names what was asked for.
 * @param WP_Block $block_instance Block asking.
 * @return mixed
 */
function isgal_filter_mirror_binding( $value, $name, $source_args, $block_instance ) {
	if ( null !== $value || 'core/post-data' !== $name ) {
		return $value;
	}
	$post_id = isset( $block_instance->context['postId'] ) ? (int) $block_instance->context['postId'] : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || ISGAL_POST_TYPE !== $post->post_type ) {
		return $value;
	}

	// 'key' is the name the argument had in the Gutenberg plugin.
	$field = '';
	if ( ! empty( $source_args['field'] ) ) {
		$field = (string) $source_args['field'];
	} elseif ( ! empty( $source_args['key'] ) ) {
		$field = (string) $source_args['key'];
	}

	switch ( $field ) {
		case 'date':
			return esc_attr( get_the_date( 'c', $post_id ) );
		case 'modified':
			return get_the_modified_date( 'U', $post_id ) > get_the_date( 'U', $post_id )
				? esc_attr( get_the_modified_date( 'c', $post_id ) )
				: '';
		case 'link':
			// Already the gallery page with its fragment: isgal_filter_mirror_permalink
			// has rewritten it by the time we get here.
			$permalink = get_permalink( $post_id );
			return false === $permalink ? $value : esc_url( $permalink );
	}
	return $value;
}
add_filter( 'block_bindings_source_value', 'isgal_filter_mirror_binding', 10, 4 );

/**
 * The photographer, for themes that print an author.
 *
 * A mirror post has post_author 0 — the photographer is not a user of this site
 * and should not be invented as one. The name is in the graph as a resolved
 * dc:creator label, so it is answered at the point a theme asks rather than
 * written into the posts table.
 *
 * Neither author filter carries post context, so the current post is read from
 * the loop and the post type checked before answering. Outside a mirror post
 * this returns the value untouched.
 *
 * @param string $name Display name.
 * @return string
 */
function isgal_filter_mirror_author( $name ) {
	$post = get_post();
	if ( ! $post instanceof WP_Post || ISGAL_POST_TYPE !== $post->post_type ) {
		return $name;
	}
	$row = isgal_mirror_read_row( $post->ID );
	return ( null !== $row && '' !== $row['creator'] ) ? $row['creator'] : $name;
}
add_filter( 'the_author', 'isgal_filter_mirror_author' );
add_filter( 'get_the_author_display_name', 'isgal_filter_mirror_author' );
