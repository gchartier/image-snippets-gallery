<?php
/**
 * Gallery-to-post index.
 *
 * Purging a page cache precisely means knowing which posts display which
 * gallery. Blocks store that in post content, which is not queryable, so this
 * mirrors it into postmeta on save: one _isgal_gallery row per gallery a post
 * references. The reverse lookup is then an ordinary meta query.
 *
 * The same index tells the admin screen which galleries exist without asking
 * ImageSnippets, and gives "refresh all" something to iterate.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This file is the index: bulk lookups over post meta that WP_Query would do in N queries. Results are small and cached by the callers.

const ISGAL_GALLERY_META = '_isgal_gallery';

/**
 * Collect gallery names from a parsed block tree, including nested blocks —
 * galleries inside columns, groups, or synced patterns must still be found.
 *
 * @param array $blocks Parsed blocks.
 * @return array Unique gallery names.
 */
function isgal_collect_galleries( array $blocks ) {
	$found = array();

	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) && 'imagesnippets/gallery' === $block['blockName'] ) {
			$gallery = isset( $block['attrs']['gallery'] ) ? trim( (string) $block['attrs']['gallery'] ) : '';
			if ( '' !== $gallery ) {
				$found[] = $gallery;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, isgal_collect_galleries( $block['innerBlocks'] ) );
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * Rewrite one post's index entries.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object.
 * @return array Gallery names now indexed for this post.
 */
function isgal_index_post( $post_id, $post = null ) {
	$post_id = absint( $post_id );
	if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return array();
	}

	if ( ! $post instanceof WP_Post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post instanceof WP_Post || isgal_is_mirror_post( $post ) ) {
		return array();
	}

	$previous = (array) get_post_meta( $post_id, ISGAL_GALLERY_META, false );
	delete_post_meta( $post_id, ISGAL_GALLERY_META );

	// Skip the parse for the overwhelming majority of posts that contain no blocks.
	$galleries = array();
	if ( has_blocks( $post->post_content ) ) {
		$galleries = isgal_collect_galleries( parse_blocks( $post->post_content ) );
		foreach ( $galleries as $gallery ) {
			add_post_meta( $post_id, ISGAL_GALLERY_META, $gallery );
		}
	}

	// A gallery this post stopped showing may now be shown nowhere; if so its
	// mirror is dead weight and goes.
	if ( array_diff( $previous, $galleries ) ) {
		isgal_prune_mirror();
	}

	return $galleries;
}
add_action( 'save_post', 'isgal_index_post', 10, 2 );

/**
 * Drop a deleted post's entries so purges never target a post that is gone.
 *
 * Hooked before deletion rather than after: by deleted_post WordPress has
 * already removed the postmeta, so there would be nothing left to read.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function isgal_deindex_post( $post_id ) {
	$had = (array) get_post_meta( absint( $post_id ), ISGAL_GALLERY_META, false );
	delete_post_meta( absint( $post_id ), ISGAL_GALLERY_META );
	if ( ! empty( $had ) ) {
		isgal_prune_mirror();
	}
}
add_action( 'before_delete_post', 'isgal_deindex_post' );

/**
 * Published posts displaying a gallery.
 *
 * Deliberately a direct query rather than WP_Query with a meta_query. WP_Query
 * caches result sets against the 'posts' last-changed marker, and changing
 * postmeta does not bump it — so immediately after a reindex the cached result
 * is wrong. That is invisible on a site with no persistent object cache and
 * reproducible on one that has it, which is the worst way for a bug to behave.
 * Purging the wrong pages is the one mistake this whole index exists to avoid.
 *
 * @param string $gallery Gallery name.
 * @return array Post IDs.
 */
function isgal_posts_for_gallery( $gallery ) {
	global $wpdb;

	$gallery = trim( (string) $gallery );
	if ( '' === $gallery ) {
		return array();
	}

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.post_id
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = %s
			    AND pm.meta_value = %s
			    AND p.post_status = 'publish'
			  LIMIT 200",
			ISGAL_GALLERY_META,
			$gallery
		)
	);

	return array_map( 'absint', is_array( $ids ) ? $ids : array() );
}

/**
 * Every gallery name currently used anywhere on the site.
 *
 * @return array
 */
function isgal_indexed_galleries() {
	global $wpdb;

	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_value ASC",
			ISGAL_GALLERY_META
		)
	);

	return is_array( $names ) ? $names : array();
}

/**
 * Every gallery block in use, with the attributes it actually carries.
 *
 * A gallery name is not enough to reconstruct what a page displays. The SPARQL
 * query — and therefore the cache key — also depends on the limit, the sort, the
 * user filter, the endpoint override and the payload profile. Rebuilding
 * attributes from defaults produces a different query than any block using a
 * non-default setting, so a refresh driven by name alone would faithfully
 * refresh an entry that nothing on the site ever reads.
 *
 * Blocks are re-parsed here rather than mirrored into more postmeta: attributes
 * would then be a second copy that can drift from the post, and this runs on
 * demand rather than on every request.
 *
 * @return array List of array( 'gallery' => string, 'attrs' => array, 'post_id' => int ).
 */
function isgal_indexed_gallery_blocks() {
	global $wpdb;

	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = %s
			    AND p.post_status = 'publish'
			  LIMIT 500",
			ISGAL_GALLERY_META
		)
	);

	$blocks = array();

	foreach ( (array) $post_ids as $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! has_blocks( $post->post_content ) ) {
			continue;
		}

		foreach ( isgal_collect_gallery_blocks( parse_blocks( $post->post_content ) ) as $attrs ) {
			$blocks[] = array(
				'gallery' => trim( (string) $attrs['gallery'] ),
				'attrs'   => $attrs,
				'post_id' => (int) $post->ID,
			);
		}
	}

	return $blocks;
}

/**
 * Collect gallery blocks with their attributes, nested blocks included.
 *
 * @param array $blocks Parsed blocks.
 * @return array List of attribute arrays.
 */
function isgal_collect_gallery_blocks( array $blocks ) {
	$found = array();

	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) && 'imagesnippets/gallery' === $block['blockName'] ) {
			$attrs = isset( $block['attrs'] ) ? (array) $block['attrs'] : array();
			if ( '' !== trim( (string) ( isset( $attrs['gallery'] ) ? $attrs['gallery'] : '' ) ) ) {
				$found[] = $attrs;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, isgal_collect_gallery_blocks( $block['innerBlocks'] ) );
		}
	}

	return $found;
}

/**
 * Rebuild the whole index. Needed once on activation, since posts saved before
 * the plugin existed never fired save_post, and available from the admin screen
 * if the index is ever suspected of drifting.
 *
 * @return int Number of posts indexed.
 */
function isgal_rebuild_index() {
	$paged   = 1;
	$indexed = 0;

	do {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 100,
				'paged'                  => $paged,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( isgal_index_post( $post->ID, $post ) ) {
				++$indexed;
			}
		}

		++$paged;
	} while ( ! empty( $query->posts ) );

	return $indexed;
}
