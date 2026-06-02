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
 * Mirrors the original plugin's input guard, but applied server-side:
 * allow word chars plus @ . - only.
 *
 * @param string $value Raw value.
 * @return string Sanitized value.
 */
function isg_sanitize_iri_segment( $value ) {
	return preg_replace( '/[^\w@.\-]/', '', (string) $value );
}

/**
 * Build the SPARQL query string from sanitized attributes.
 *
 * @param array $a Sanitized attributes (gallery, userId, order, orderBy, limit).
 * @return string SPARQL query.
 */
function isg_build_sparql( array $a ) {
	$gallery = isg_sanitize_iri_segment( $a['gallery'] );
	$user_id = isg_sanitize_iri_segment( $a['userId'] );

	$order   = ( 'desc' === strtolower( $a['order'] ) ) ? 'desc' : 'asc';
	$orderby = ( 'date' === strtolower( $a['orderBy'] ) ) ? 'date' : 'title';
	$limit   = max( 1, min( 200, (int) $a['limit'] ) );

	$creator = '';
	if ( '' !== $user_id ) {
		$creator = "  ?graph dcterms:creator <" . ISG_USER_BASE . $user_id . ">.\n";
	}

	$dataset = ISG_DATASET_BASE . $gallery;

	return <<<SPARQL
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX dc: <http://purl.org/dc/elements/1.1/>
PREFIX lio: <https://w3id.org/lio/v1#>
PREFIX schema: <http://schema.org/>
PREFIX photoshop: <http://ns.adobe.com/photoshop/1.0/>
PREFIX Iptc4xmpCore: <http://www.iptc.org/std/Iptc4xmpCore/1.0/xmlns/>
PREFIX plus: <http://ns.useplus.org/ldf/xmp/1.0/>
PREFIX xmpRights: <http://ns.adobe.com/xap/1.0/rights/>

SELECT * WHERE { graph ?page {
  ?image lio:isIn <{$dataset}>.
  ?image schema:thumbnail ?thumb.
  optional { ?image dc:title ?title. }
  optional { ?image dc:creator ?creator. }
  optional { ?image dc:rights ?rights. }
  optional { ?image photoshop:DateCreated ?date. }
  optional { ?image Iptc4xmpCore:AltTextAccessibility ?alt. }
  optional { ?image plus:LicensorURL ?url. }
  optional { ?image xmpRights:WebStatement ?web. }
{$creator}}} order by {$order}(?{$orderby}) limit {$limit}
SPARQL;
}

/**
 * Run the SPARQL query against the endpoint, with transient caching.
 *
 * @param string $endpoint SPARQL endpoint URL.
 * @param string $query    SPARQL query.
 * @return array|WP_Error  List of result rows (assoc arrays of var => value), or WP_Error.
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

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $code ) {
		return new WP_Error( 'isg_http', sprintf( 'SPARQL endpoint returned HTTP %d', $code ) );
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! isset( $json['results']['bindings'] ) ) {
		return new WP_Error( 'isg_parse', 'Unexpected SPARQL response format.' );
	}

	$rows = array();
	foreach ( $json['results']['bindings'] as $binding ) {
		$row = array();
		foreach ( $binding as $var => $cell ) {
			$row[ $var ] = isset( $cell['value'] ) ? $cell['value'] : '';
		}
		$rows[] = $row;
	}

	if ( $cache_ttl > 0 ) {
		set_transient( $cache_key, $rows, $cache_ttl );
	}

	return $rows;
}

/**
 * Build a JSON-LD ImageObject array for the result set (server-side, crawlable).
 *
 * @param array $rows Result rows.
 * @return string <script> tag with JSON-LD, or empty string.
 */
function isg_jsonld( array $rows ) {
	if ( empty( $rows ) ) {
		return '';
	}

	$items = array();
	foreach ( $rows as $row ) {
		$item = array(
			'@type'      => 'ImageObject',
			'contentUrl' => isset( $row['thumb'] ) ? $row['thumb'] : '',
		);
		if ( ! empty( $row['title'] ) ) {
			$item['name'] = $row['title'];
		}
		if ( ! empty( $row['creator'] ) ) {
			$item['creator'] = $row['creator'];
		}
		if ( ! empty( $row['date'] ) ) {
			$item['dateCreated'] = $row['date'];
		}
		if ( ! empty( $row['web'] ) ) {
			$item['license'] = $row['web'];
		}
		if ( ! empty( $row['url'] ) ) {
			$item['acquireLicensePage'] = $row['url'];
		}
		if ( ! empty( $row['alt'] ) ) {
			$item['description'] = $row['alt'];
		}
		if ( ! empty( $row['page'] ) ) {
			$item['url'] = $row['page'];
		}
		$items[] = $item;
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $items,
	);

	return '<script type="application/ld+json">'
		. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>';
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
		'order'          => 'asc',
		'orderBy'        => 'title',
		'limit'          => 40,
		'thumbSize'      => 'medium',
	);
	$a = wp_parse_args( $attributes, $defaults );

	$endpoint = $a['endpoint'] ? esc_url_raw( $a['endpoint'] ) : ISG_DEFAULT_ENDPOINT;

	$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'justified' ), true ) ? $a['layout'] : 'grid';
	$size   = in_array( $a['thumbSize'], array( 'small', 'medium', 'large' ), true ) ? $a['thumbSize'] : 'medium';

	$wrapper_attributes = get_block_wrapper_attributes(
		array(
			'class' => 'isg-gallery isg-layout-' . $layout . ' isg-size-' . $size,
		)
	);

	// Nothing configured yet (e.g. fresh block) — show a hint, no query.
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

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php if ( $a['displayTitle'] && '' !== $a['gallery'] ) : ?>
			<p class="isg-title"><?php echo esc_html( $a['gallery'] ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="isg-message"><?php echo esc_html( sprintf( /* translators: %s: gallery name */ __( '%s — no images available.', 'image-snippets-gallery' ), $a['gallery'] ) ); ?></p>
		<?php else : ?>
			<div class="isg-grid">
				<?php foreach ( $rows as $row ) : ?>
					<figure class="isg-item" vocab="https://schema.org/" typeof="ImageObject">
						<a href="<?php echo esc_url( isset( $row['page'] ) ? $row['page'] : '#' ); ?>">
							<img
								src="<?php echo esc_url( isset( $row['thumb'] ) ? $row['thumb'] : '' ); ?>"
								alt="<?php echo esc_attr( isset( $row['alt'] ) ? $row['alt'] : ( isset( $row['title'] ) ? $row['title'] : '' ) ); ?>"
								loading="lazy"
								decoding="async"
								property="contentUrl"
							/>
						</a>
						<?php if ( ! empty( $row['web'] ) ) : ?>
							<span property="license" hidden><?php echo esc_html( $row['web'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $row['url'] ) ) : ?>
							<span property="acquireLicensePage" hidden><?php echo esc_html( $row['url'] ); ?></span>
						<?php endif; ?>
						<?php if ( $a['displayCaption'] && ! empty( $row['title'] ) ) : ?>
							<figcaption class="isg-caption" property="name"><?php echo esc_html( $row['title'] ); ?></figcaption>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</div>
			<?php
			// translators: shown as a footer credit line when a user filter is set.
			if ( '' !== $a['userId'] && ! empty( $rows[0]['rights'] ) ) :
				?>
				<p class="isg-footer"><?php echo esc_html( sprintf( __( 'Images %s', 'image-snippets-gallery' ), $rows[0]['rights'] ) ); ?></p>
			<?php endif; ?>
			<?php echo isg_jsonld( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
