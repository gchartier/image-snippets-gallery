<?php
/**
 * Saved sources: galleries that are made of a query rather than a dataset.
 *
 * A source is written once, on the Tools screen, given a name, and from then
 * on is chosen in a block like any gallery. The name is what the block keeps
 * ("~ospreys"); the query can be rewritten without the gallery becoming a
 * different gallery, so its mirror, its arranged order and the pages showing
 * it all carry on.
 *
 * The person writes only the part that says which images belong: a SPARQL
 * pattern binding ?image. What is selected, the thumbnail condition, the
 * ceiling on how many, and the fetch of each image's whole graph stay the
 * plugin's, so a source can choose images and do nothing else.
 *
 * A name begins with "~", which no dataset name can (the dataset IRI builder
 * strips it), so a source never shadows a dataset.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ceiling on images one source may list. A dataset grows when its owner adds
// to it; a query grows when anyone on ImageSnippets describes another image
// the same way, so it is held closer. A block shows at most 200. Filterable.
const ISGAL_SOURCE_MAX_IMAGES = 200;

/**
 * Whether a gallery name is a saved source's.
 *
 * @param string $gallery Gallery name.
 * @return bool
 */
function isgal_is_source_name( $gallery ) {
	return 0 === strpos( ltrim( (string) $gallery ), '~' );
}

/**
 * The capability needed to write a source. Placing one in a block needs no
 * more than editing the post does.
 *
 * @return string
 */
function isgal_sources_cap() {
	/**
	 * Filter the capability required to create, edit and delete saved sources.
	 *
	 * @param string $cap Capability. Default 'manage_options'.
	 */
	return (string) apply_filters( 'isgal_custom_sparql_cap', 'manage_options' );
}

/**
 * What to call a gallery in front of a visitor: a source's label, or the
 * gallery name as it is.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $gallery  Gallery name.
 * @return string
 */
function isgal_gallery_label( $endpoint, $gallery ) {
	if ( isgal_is_source_name( $gallery ) ) {
		$source = isgal_gallery_source( $endpoint, $gallery );
		if ( ! empty( $source['label'] ) ) {
			return (string) $source['label'];
		}
	}
	return (string) $gallery;
}

/**
 * Check a pattern before it is kept or run.
 *
 * Not the security boundary — the endpoint is read-only and an administrator
 * chose it — but a pattern can still reach out of it (SERVICE asks the
 * endpoint to query another server) or close the plugin's own braces and
 * carry on as a different query, and neither is what a source is for.
 *
 * @param string $where SPARQL pattern.
 * @return true|WP_Error
 */
function isgal_validate_source_pattern( $where ) {
	$where = trim( (string) $where );
	if ( '' === $where ) {
		return new WP_Error( 'isgal_source_empty', __( 'Write the pattern that says which images belong.', 'image-snippets-gallery' ) );
	}
	if ( strlen( $where ) > 4000 ) {
		return new WP_Error( 'isgal_source_long', __( 'The pattern is longer than 4000 characters.', 'image-snippets-gallery' ) );
	}

	// Judge the structure with IRIs and quoted strings taken out, so a brace
	// or a keyword inside one is not mistaken for the query's own.
	$bare = preg_replace( '/<[^<>\s]*>|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', ' ', $where );

	if ( ! preg_match( '/\?image\b/', $bare ) ) {
		return new WP_Error( 'isgal_source_no_image', __( 'The pattern must use ?image for the images it chooses.', 'image-snippets-gallery' ) );
	}
	// Variables and prefixed names are the person's own words (?from, schema:copy).
	$bare = preg_replace( '/[?$]\w+|\w*:[\w\-.]+/', ' ', $bare );
	if ( preg_match( '/\b(SERVICE|INSERT|DELETE|LOAD|CLEAR|DROP|CREATE|COPY|MOVE|WITH|USING|PREFIX|BASE|FROM)\b/i', $bare, $m ) ) {
		return new WP_Error(
			'isgal_source_keyword',
			sprintf(
				/* translators: %s: a SPARQL keyword */
				__( '%s cannot be used in a source. Write only the pattern, with full IRIs or the prefixes listed below.', 'image-snippets-gallery' ),
				strtoupper( $m[1] )
			)
		);
	}

	$depth = 0;
	foreach ( str_split( $bare ) as $char ) {
		if ( '{' === $char ) {
			++$depth;
		} elseif ( '}' === $char && --$depth < 0 ) {
			break;
		}
	}
	if ( 0 !== $depth ) {
		return new WP_Error( 'isgal_source_braces', __( 'The braces in the pattern do not match.', 'image-snippets-gallery' ) );
	}

	return true;
}

/**
 * The gallery name a label becomes.
 *
 * @param string $label Label as typed.
 * @return string "~slug", or '' when nothing usable is left.
 */
function isgal_source_name( $label ) {
	$slug = sanitize_title( (string) $label );
	return '' === $slug ? '' : '~' . $slug;
}

/**
 * Every saved source, by label.
 *
 * @return array List of [ 'name', 'label', 'where', 'term' => WP_Term, 'images' => int, 'synced' => int ].
 */
function isgal_list_sources() {
	$terms = get_terms(
		array(
			'taxonomy'   => ISGAL_TAXONOMY,
			'hide_empty' => false,
			'meta_key'   => ISGAL_TERM_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of terms.
		)
	);
	$list  = array();
	if ( is_wp_error( $terms ) ) {
		return $list;
	}
	foreach ( $terms as $term ) {
		$source = json_decode( (string) get_term_meta( $term->term_id, ISGAL_TERM_SOURCE, true ), true );
		if ( ! is_array( $source ) || empty( $source['kind'] ) || 'sparql' !== $source['kind'] ) {
			continue;
		}
		$list[] = array(
			'name'   => (string) get_term_meta( $term->term_id, ISGAL_TERM_GALLERY, true ),
			'label'  => isset( $source['label'] ) ? (string) $source['label'] : $term->name,
			'where'  => isset( $source['where'] ) ? (string) $source['where'] : '',
			'term'   => $term,
			'images' => (int) $term->count,
			'synced' => isgal_gallery_synced_at( $term ),
		);
	}
	usort(
		$list,
		function ( $x, $y ) {
			return strcasecmp( $x['label'], $y['label'] );
		}
	);
	return $list;
}

/**
 * One saved source by its gallery name.
 *
 * @param string $name Gallery name ("~slug").
 * @return array|null As isgal_list_sources().
 */
function isgal_get_source( $name ) {
	foreach ( isgal_list_sources() as $source ) {
		if ( $source['name'] === $name ) {
			return $source;
		}
	}
	return null;
}

/**
 * Create a source, or rewrite one.
 *
 * Rewriting keeps the name, and with it everything that hangs off the gallery;
 * the mirror is marked never-synced so the next view, or the caller, fetches
 * what the new pattern chooses.
 *
 * @param string $label Label shown to people.
 * @param string $where SPARQL pattern binding ?image.
 * @param string $name  Existing source to rewrite, or '' to create one.
 * @return string|WP_Error The gallery name to put in a block.
 */
function isgal_save_source( $label, $where, $name = '' ) {
	$label = trim( sanitize_text_field( (string) $label ) );
	$where = trim( (string) $where );
	if ( '' === $label ) {
		return new WP_Error( 'isgal_source_label', __( 'Give the source a name.', 'image-snippets-gallery' ) );
	}
	$valid = isgal_validate_source_pattern( $where );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$endpoint = isgal_default_endpoint();
	if ( '' === $name ) {
		$name = isgal_source_name( $label );
		if ( '' === $name ) {
			return new WP_Error( 'isgal_source_label', __( 'Give the source a name with letters or numbers in it.', 'image-snippets-gallery' ) );
		}
		if ( isgal_gallery_term( $endpoint, $name ) instanceof WP_Term ) {
			return new WP_Error( 'isgal_source_exists', __( 'A source with that name already exists.', 'image-snippets-gallery' ) );
		}
	} elseif ( null === isgal_get_source( $name ) ) {
		return new WP_Error( 'isgal_source_missing', __( 'That source no longer exists.', 'image-snippets-gallery' ) );
	}

	$term = isgal_gallery_term( $endpoint, $name, true );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'isgal_source_term', __( 'The source could not be saved.', 'image-snippets-gallery' ) );
	}

	$before = isgal_gallery_source( $endpoint, $name );
	// Slashed: term meta is unslashed on the way in, and a pattern may hold backslashes.
	update_term_meta(
		$term->term_id,
		ISGAL_TERM_SOURCE,
		wp_slash(
			wp_json_encode(
				array(
					'kind'  => 'sparql',
					'label' => $label,
					'where' => $where,
				)
			)
		)
	);
	if ( ! isset( $before['where'] ) || $before['where'] !== $where ) {
		delete_term_meta( $term->term_id, ISGAL_TERM_SYNCED );
	}

	return $name;
}

/**
 * Delete a source and whatever it mirrored. Images another gallery also holds stay.
 *
 * @param string $name Gallery name ("~slug").
 * @return bool
 */
function isgal_delete_source( $name ) {
	$source = isgal_get_source( $name );
	if ( null === $source ) {
		return false;
	}
	// Without its source the term is an ordinary label, and goes with its images.
	delete_term_meta( $source['term']->term_id, ISGAL_TERM_SOURCE );
	isgal_mirror_drop_gallery( $source['term'] );
	return true;
}

/**
 * Run a pattern without keeping anything: how many images, how long it took,
 * and a few thumbnails to recognise the result by.
 *
 * @param string $where SPARQL pattern.
 * @return array|WP_Error [ 'count' => int, 'capped' => bool, 'seconds' => float, 'thumbs' => string[] ].
 */
function isgal_test_source( $where ) {
	$valid = isgal_validate_source_pattern( $where );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$cap     = isgal_source_cap();
	$started = microtime( true );
	$rows    = isgal_sparql_json( isgal_default_endpoint(), isgal_build_sparql_list( trim( (string) $where ), $cap ), 30 );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$thumbs = array();
	foreach ( $rows as $b ) {
		if ( isset( $b['thumb_']['value'] ) && count( $thumbs ) < 12 ) {
			$thumbs[] = esc_url_raw( $b['thumb_']['value'] );
		}
	}
	return array(
		'count'   => count( $rows ),
		'capped'  => count( $rows ) >= $cap,
		'seconds' => round( microtime( true ) - $started, 2 ),
		'thumbs'  => $thumbs,
	);
}

/**
 * How many images a source may list.
 *
 * @return int
 */
function isgal_source_cap() {
	/**
	 * Filter the ceiling on images listed for one saved source.
	 *
	 * @param int $cap Default ISGAL_SOURCE_MAX_IMAGES.
	 */
	return max( 1, (int) apply_filters( 'isgal_source_max_images', ISGAL_SOURCE_MAX_IMAGES ) );
}
