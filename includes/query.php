<?php
/**
 * Server-side SPARQL query, caching, and HTML/JSON-LD rendering.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a value destined for interpolation inside a SPARQL IRI.
 * Allow word chars plus @ . - only.
 *
 * @param string $value Raw value.
 * @return string Sanitized value.
 */
function isg_sanitize_iri_segment( $value ) {
	return preg_replace( '/[^\w@.\-]/', '', (string) $value );
}

/**
 * Turn an image identifier / filename into a human-ish label.
 * e.g. "Trevor%20Welding-20120225-00087.jpg" -> "Trevor Welding".
 *
 * @param string $url Image IRI or contentUrl.
 * @return string
 */
function isg_humanize_filename( $url ) {
	$base = rawurldecode( basename( (string) $url ) );
	$base = preg_replace( '/\.[a-z0-9]{2,4}$/i', '', $base ); // strip extension
	$base = preg_replace( '/[ _-]+\d{6,}.*$/', '', $base );    // strip trailing date/id runs
	$base = trim( str_replace( array( '_', '-' ), ' ', $base ) );
	return $base;
}

/**
 * First non-empty string from a list.
 *
 * @param array $candidates Ordered candidates.
 * @return string
 */
function isg_first( array $candidates ) {
	foreach ( $candidates as $c ) {
		if ( '' !== trim( (string) $c ) ) {
			return (string) $c;
		}
	}
	return '';
}

/**
 * Build the SPARQL query string from sanitized attributes.
 *
 * Uses GROUP BY + SAMPLE so each image is one row even when it has multiple
 * values for a predicate; multivalued `about` is collapsed with GROUP_CONCAT.
 *
 * @param array $a Sanitized attributes.
 * @return string SPARQL query.
 */
function isg_build_sparql( array $a ) {
	$gallery = isg_sanitize_iri_segment( $a['gallery'] );
	$user_id = isg_sanitize_iri_segment( $a['userId'] );

	$order   = ( 'asc' === strtolower( $a['order'] ) ) ? 'ASC' : 'DESC';
	$orderby = ( 'title' === strtolower( $a['orderBy'] ) ) ? '?title_' : '?date_';
	$limit   = max( 1, min( 200, (int) $a['limit'] ) );

	$dataset = ISG_DATASET_BASE . $gallery;

	// Optional filter: images whose named graph is created by a given user.
	// Best-effort; named-graph creator metadata may not be present for all stores.
	$creator = '';
	if ( '' !== $user_id ) {
		$creator = '  ?page <http://purl.org/dc/terms/creator> <' . ISG_USER_BASE . $user_id . ">.\n";
	}

	return <<<SPARQL
PREFIX dc: <http://purl.org/dc/elements/1.1/>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX lio: <https://w3id.org/lio/v1#>
PREFIX schema: <http://schema.org/>
PREFIX photoshop: <http://ns.adobe.com/photoshop/1.0/>
PREFIX Iptc4xmpCore: <http://www.iptc.org/std/Iptc4xmpCore/1.0/xmlns/>
PREFIX xmpRights: <http://ns.adobe.com/xap/1.0/rights/>
PREFIX plus: <http://ns.useplus.org/ldf/xmp/1.0/>

SELECT ?image ?page
  (SAMPLE(?thumb)    AS ?thumb_)
  (SAMPLE(?content)  AS ?content_)
  (SAMPLE(?title)    AS ?title_)
  (SAMPLE(?name)     AS ?name_)
  (SAMPLE(?desc)     AS ?desc_)
  (SAMPLE(?alt)      AS ?alt_)
  (SAMPLE(?date)     AS ?date_)
  (SAMPLE(?rights)   AS ?rights_)
  (SAMPLE(?web)      AS ?web_)
  (SAMPLE(?licurl)   AS ?licurl_)
  (SAMPLE(?location) AS ?location_)
  (GROUP_CONCAT(DISTINCT ?about; separator="|") AS ?abouts_)
WHERE { graph ?page {
  ?image lio:isIn <{$dataset}>.
  ?image schema:thumbnail ?thumb.
  optional { ?image schema:contentUrl ?content. }
  optional { ?image dc:title ?title. }
  optional { ?image schema:name ?name. }
  optional { ?image Iptc4xmpCore:ExtDescrAccessibility ?desc. }
  optional { ?image Iptc4xmpCore:AltTextAccessibility ?alt. }
  optional { ?image photoshop:DateCreated ?date. }
  optional { ?image dc:rights ?rights. }
  optional { ?image xmpRights:WebStatement ?web. }
  optional { ?image plus:LicensorURL ?licurl. }
  optional { ?image lio:hasSceneLocation ?location. }
  optional { ?image schema:about ?about. }
}
{$creator}}} GROUP BY ?image ?page ORDER BY {$order}({$orderby}) LIMIT {$limit}
SPARQL;
}

/**
 * Run the SPARQL query against the endpoint, with transient caching.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @return array|WP_Error  Normalized rows, or WP_Error.
 */
function isg_run_query( $endpoint, $query ) {
	$cache_ttl = (int) apply_filters( 'isg_cache_ttl', 10 * MINUTE_IN_SECONDS );
	$cache_key = 'isg_' . md5( $endpoint . '|' . $query );

	if ( $cache_ttl > 0 ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	// add_query_arg does NOT url-encode values, so encode the query ourselves.
	$url = add_query_arg( 'query', rawurlencode( $query ), $endpoint );

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/sparql-results+json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'isg_http', sprintf( 'SPARQL endpoint returned HTTP %d', $code ) );
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! isset( $json['results']['bindings'] ) ) {
		return new WP_Error( 'isg_parse', 'Unexpected SPARQL response format.' );
	}

	$rows = array();
	foreach ( $json['results']['bindings'] as $binding ) {
		$g = static function ( $key ) use ( $binding ) {
			return isset( $binding[ $key ]['value'] ) ? $binding[ $key ]['value'] : '';
		};
		$abouts = array_filter( array_map( 'trim', explode( '|', $g( 'abouts_' ) ) ) );

		$rows[] = array(
			'image'    => $g( 'image' ),
			'page'     => $g( 'page' ),
			'thumb'    => $g( 'thumb_' ),
			'content'  => $g( 'content_' ),
			'title'    => $g( 'title_' ),
			'name'     => $g( 'name_' ),
			'desc'     => $g( 'desc_' ),
			'alt'      => $g( 'alt_' ),
			'date'     => $g( 'date_' ),
			'rights'   => $g( 'rights_' ),
			'web'      => $g( 'web_' ),
			'licurl'   => $g( 'licurl_' ),
			'location' => $g( 'location_' ),
			'abouts'   => array_values( $abouts ),
		);
	}

	if ( $cache_ttl > 0 ) {
		set_transient( $cache_key, $rows, $cache_ttl );
	}

	return $rows;
}

/**
 * Resolve the visible title for a row (title -> name -> optional filename).
 *
 * @param array $row          Row.
 * @param bool  $use_filename Whether to fall back to a humanized filename.
 * @return string
 */
function isg_row_title( array $row, $use_filename ) {
	$title = isg_first( array( $row['title'], $row['name'] ) );
	if ( '' === $title && $use_filename ) {
		$title = isg_humanize_filename( $row['image'] ? $row['image'] : $row['content'] );
	}
	return $title;
}

/**
 * Resolve alt text (AltText -> extended description -> title).
 *
 * @param array $row Row.
 * @return string
 */
function isg_row_alt( array $row ) {
	return isg_first( array( $row['alt'], $row['desc'], $row['title'], $row['name'] ) );
}

/**
 * Build schema.org JSON-LD for the gallery as an ImageGallery of ImageObjects.
 *
 * @param array  $rows         Rows.
 * @param string $gallery      Gallery name.
 * @param bool   $use_filename Filename fallback for names.
 * @return string <script> tag, or empty string.
 */
function isg_jsonld( array $rows, $gallery, $use_filename ) {
	if ( empty( $rows ) ) {
		return '';
	}

	$images   = array();
	$position = 0;
	foreach ( $rows as $row ) {
		++$position;
		$item = array(
			'@type'        => 'ImageObject',
			'position'     => $position,
			'contentUrl'   => $row['content'] ? $row['content'] : $row['thumb'],
			'thumbnailUrl' => $row['thumb'],
		);

		$name = isg_row_title( $row, $use_filename );
		if ( '' !== $name ) {
			$item['name'] = $name;
		}
		if ( '' !== $row['desc'] ) {
			$item['description'] = $row['desc'];
		}
		if ( '' !== $row['date'] ) {
			$item['dateCreated'] = $row['date'];
		}
		if ( '' !== $row['web'] ) {
			$item['license'] = $row['web'];
		}
		if ( '' !== $row['licurl'] ) {
			$item['acquireLicensePage'] = $row['licurl'];
		}
		if ( '' !== $row['page'] ) {
			$item['url'] = $row['page'];
		}
		if ( '' !== $row['location'] ) {
			$item['contentLocation'] = array( '@id' => $row['location'] );
		}
		if ( ! empty( $row['abouts'] ) ) {
			$about = array_map(
				static function ( $id ) {
					return array( '@id' => $id );
				},
				$row['abouts']
			);
			$item['about'] = ( 1 === count( $about ) ) ? $about[0] : $about;
		}

		$images[] = $item;
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@type'    => 'ImageGallery',
		'name'     => $gallery,
		'image'    => $images,
	);

	return '<script type="application/ld+json">'
		. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>';
}

/**
 * Editor-only data-quality notice. Rendered only inside the block-editor
 * preview (a REST request), never on the public front end.
 *
 * @param array $rows Rows.
 * @return string
 */
function isg_editor_notice( array $rows ) {
	if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || empty( $rows ) ) {
		return '';
	}

	$no_title = 0;
	$no_alt   = 0;
	foreach ( $rows as $row ) {
		if ( '' === isg_first( array( $row['title'], $row['name'] ) ) ) {
			++$no_title;
		}
		if ( '' === isg_first( array( $row['alt'], $row['desc'] ) ) ) {
			++$no_alt;
		}
	}

	if ( 0 === $no_title && 0 === $no_alt ) {
		return '';
	}

	$total = count( $rows );
	$msg   = sprintf(
		/* translators: 1: missing-title count, 2: missing-alt count, 3: total images */
		__( '%1$d of %3$d images have no title and %2$d have no alt text. Add them in ImageSnippets to improve accessibility and search visibility. (This notice is editor-only.)', 'image-snippets-gallery' ),
		$no_title,
		$no_alt,
		$total
	);

	return '<p class="isg-editor-notice" style="padding:.5em .75em;border:1px solid #f0b849;background:#fcf9e8;border-radius:4px;font-size:.85em">⚠ '
		. esc_html( $msg ) . '</p>';
}

/**
 * Render the gallery HTML for a set of block attributes. Called from render.php.
 *
 * @param array $attributes Block attributes.
 * @return string HTML.
 */
function isg_render_gallery( array $attributes ) {
	$defaults = array(
		'gallery'        => '',
		'userId'         => '',
		'endpoint'       => '',
		'displayCaption' => false,
		'displayTitle'   => false,
		'layout'         => 'grid',
		'order'          => 'desc',
		'orderBy'        => 'date',
		'limit'          => 40,
		'thumbSize'      => 'medium',
		'aspectRatio'    => '4-3',
		'useFilename'    => false,
	);
	$a = wp_parse_args( $attributes, $defaults );

	$endpoint = $a['endpoint'] ? esc_url_raw( $a['endpoint'] ) : ISG_DEFAULT_ENDPOINT;

	$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'justified' ), true ) ? $a['layout'] : 'grid';
	$size   = in_array( $a['thumbSize'], array( 'small', 'medium', 'large' ), true ) ? $a['thumbSize'] : 'medium';
	$ratio  = in_array( $a['aspectRatio'], array( 'original', '1-1', '4-3', '3-2', '16-9' ), true ) ? $a['aspectRatio'] : '4-3';
	// Aspect-ratio cropping is incompatible with true masonry (variable heights).
	if ( 'masonry' === $layout ) {
		$ratio = 'original';
	}

	$classes            = 'isg-gallery isg-layout-' . $layout . ' isg-size-' . $size . ' isg-ratio-' . $ratio;
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => $classes ) );

	if ( '' === trim( (string) $a['gallery'] ) ) {
		return sprintf(
			'<div %s><p class="isg-message">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Enter an ImageSnippets gallery name in the block settings.', 'image-snippets-gallery' )
		);
	}

	$query = isg_build_sparql( $a );
	$rows  = isg_run_query( $endpoint, $query );

	if ( is_wp_error( $rows ) ) {
		return sprintf(
			'<div %s><p class="isg-message isg-error">%s</p></div>',
			$wrapper_attributes,
			esc_html__( 'Unable to load gallery right now.', 'image-snippets-gallery' )
		);
	}

	$use_filename = (bool) $a['useFilename'];
	$position     = 0;

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php echo isg_editor_notice( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( $a['displayTitle'] ) : ?>
			<p class="isg-title"><?php echo esc_html( $a['gallery'] ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="isg-message"><?php echo esc_html( sprintf( /* translators: %s: gallery name */ __( '%s — no images available.', 'image-snippets-gallery' ), $a['gallery'] ) ); ?></p>
		<?php else : ?>
			<div class="isg-grid">
				<?php
				foreach ( $rows as $row ) :
					++$position;
					$title = isg_row_title( $row, $use_filename );
					$alt   = isg_row_alt( $row );
					// Always give the link an accessible name, even when alt is empty.
					$label = isg_first(
						array(
							$alt,
							$title,
							sprintf( /* translators: 1: gallery name, 2: position */ __( '%1$s image %2$d', 'image-snippets-gallery' ), $a['gallery'], $position ),
						)
					);
					?>
					<figure class="isg-item" vocab="https://schema.org/" typeof="ImageObject">
						<a href="<?php echo esc_url( $row['page'] ? $row['page'] : '#' ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
							<img
								src="<?php echo esc_url( $row['thumb'] ); ?>"
								alt="<?php echo esc_attr( $alt ); ?>"
								loading="lazy"
								decoding="async"
								property="contentUrl"
							/>
						</a>
						<?php if ( '' !== $row['web'] ) : ?>
							<span property="license" hidden><?php echo esc_html( $row['web'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $row['licurl'] ) : ?>
							<span property="acquireLicensePage" hidden><?php echo esc_html( $row['licurl'] ); ?></span>
						<?php endif; ?>
						<?php if ( $a['displayCaption'] && '' !== $title ) : ?>
							<figcaption class="isg-caption" property="name"><?php echo esc_html( $title ); ?></figcaption>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php
			if ( '' !== $a['userId'] && '' !== $rows[0]['rights'] ) :
				?>
				<p class="isg-footer"><?php echo esc_html( sprintf( /* translators: %s: rights statement */ __( 'Images %s', 'image-snippets-gallery' ), $rows[0]['rights'] ) ); ?></p>
			<?php endif; ?>
			<?php echo isg_jsonld( $rows, $a['gallery'], $use_filename ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
