<?php
/**
 * Whole-named-graph retrieval, and the JSON-LD built from it.
 *
 * ImageSnippets stores one named graph per image, and that graph already
 * contains a schema.org projection, the LIO statements schema.org cannot
 * express, and an rdfs:label for every entity it references. The previous
 * query asked for a fixed list of columns and hand-mapped them into
 * schema.org, which threw away the LIO statements — the part that makes this
 * ImageSnippets rather than any other gallery — and left schema:about as an
 * opaque IRI a crawler can do nothing with.
 *
 * So: ask for the graph, pass it through, and derive the display fields from
 * it rather than querying for them separately.
 *
 * The emitted payload has two audiences in one document:
 *
 *   - The default graph holds flat schema.org nodes. This is what Google reads.
 *   - Named graph objects hold each image's triples verbatim, keeping the
 *     attribution intact, because who asserted what is the entire point of a
 *     named graph. Consumers that do not understand them skip them.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prefixes emitted in the JSON-LD @context and used to compact IRIs.
 *
 * Note that the corpus uses http://schema.org/, not https. Compacting against
 * the wrong one would silently produce terms Google does not recognize.
 *
 * @return array Prefix to namespace IRI.
 */
function isgal_jsonld_prefixes() {
	return array(
		// schema.org is the default vocabulary so its terms can be emitted bare —
		// "name", "ImageObject" — rather than as CURIEs. Both are correct JSON-LD
		// and mean the same thing, but bare terms are what every structured-data
		// validator and SEO tool expects to see, and this payload exists to be
		// read by those. Terms carrying an explicit prefix are unaffected, so the
		// named graphs below keep their exact vocabularies.
		'@vocab'       => 'http://schema.org/',
		'schema'       => 'http://schema.org/',
		'lio'          => 'https://w3id.org/lio/v1#',
		'dc'           => 'http://purl.org/dc/elements/1.1/',
		'dcterms'      => 'http://purl.org/dc/terms/',
		'rdf'          => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
		'rdfs'         => 'http://www.w3.org/2000/01/rdf-schema#',
		'foaf'         => 'http://xmlns.com/foaf/0.1/',
		'photoshop'    => 'http://ns.adobe.com/photoshop/1.0/',
		'Iptc4xmpCore' => 'http://www.iptc.org/std/Iptc4xmpCore/1.0/xmlns/',
		'xmpRights'    => 'http://ns.adobe.com/xap/1.0/rights/',
		'plus'         => 'http://ns.useplus.org/ldf/xmp/1.0/',
		'exif'         => 'http://ns.adobe.com/exif/1.0/',
		'wdt'          => 'http://www.wikidata.org/prop/direct/',
		'dbr'          => 'http://dbpedia.org/resource/',
		'aat'          => 'http://vocab.getty.edu/aat/',
	);
}

/**
 * Predicate namespaces that describe the ImageSnippets HTML page rather than
 * the image: its Open Graph chrome, its Twitter card, its stylesheet.
 *
 * 'twitter:' has no trailing separator because the predicate in the corpus is
 * the literal string "twitter:card", which is not an absolute IRI at all. The
 * corpus writes the Open Graph namespace with https; the specification writes
 * it with http, so both are named.
 *
 * @return array
 */
function isgal_graph_chrome_prefixes() {
	return array(
		'http://ogp.me/ns#',
		'https://ogp.me/ns#',
		'twitter:',
		'http://www.w3.org/1999/xhtml/vocab#',
	);
}

/**
 * Predicate namespaces dropped from the 'provenance' profile: the page chrome,
 * and the camera sensor's EXIF. None of it is provenance about the image's
 * meaning, and together it is a large fraction of every graph.
 *
 * @return array
 */
function isgal_graph_dropped_prefixes() {
	return array_merge( isgal_graph_chrome_prefixes(), array( 'http://ns.adobe.com/exif/1.0/' ) );
}

/**
 * Predicates whose object identifies something the image depicts or is about.
 *
 * The corpus is not uniform: some galleries carry schema:about and lio:depicts
 * pointing at DBpedia entities, others carry only lio:hasTag with a plain
 * string. Both have to work, so collect from all of them and sort out IRIs from
 * literals afterwards.
 *
 * @return array
 */
function isgal_graph_about_predicates() {
	return array(
		'http://schema.org/about',
		'http://schema.org/mentions',
		'https://w3id.org/lio/v1#depicts',
		'https://w3id.org/lio/v1#hasTag',
		'https://w3id.org/lio/v1#shows',
		'http://xmlns.com/foaf/0.1/depiction',
		'http://www.wikidata.org/prop/direct/P180',
	);
}

/**
 * Where an image's date can come from, in the order the plugin trusts them by
 * default. The corpus (full dump, 2026-08-28) carries exif:DateTimeOriginal on
 * 27% of images — the camera's capture time — and photoshop:DateCreated on
 * 54%, which on some galleries is when the image was catalogued rather than
 * taken. A block may reorder these; the last resort is a year in the rights
 * statement ("© 2012 …").
 *
 * @return array key => [ 'label' => string, 'predicates' => string[] ]
 */
function isgal_date_sources() {
	return array(
		'DateTimeOriginal' => array(
			'label'      => __( 'Capture time (EXIF DateTimeOriginal)', 'image-snippets-gallery' ),
			'predicates' => array( 'http://ns.adobe.com/exif/1.0/DateTimeOriginal' ),
		),
		'CreateDate'       => array(
			'label'      => __( 'File created (EXIF/XMP CreateDate)', 'image-snippets-gallery' ),
			'predicates' => array( 'http://ns.adobe.com/exif/1.0/CreateDate', 'http://ns.adobe.com/xap/1.0/CreateDate' ),
		),
		'DateCreated'      => array(
			'label'      => __( 'Date created (IPTC/Photoshop DateCreated)', 'image-snippets-gallery' ),
			'predicates' => array( 'http://ns.adobe.com/photoshop/1.0/DateCreated' ),
		),
		'ModifyDate'       => array(
			'label'      => __( 'Last modified (EXIF ModifyDate)', 'image-snippets-gallery' ),
			'predicates' => array( 'http://ns.adobe.com/exif/1.0/ModifyDate', 'http://ns.adobe.com/xap/1.0/ModifyDate' ),
		),
		'rights'           => array(
			'label'      => __( 'Year in the rights statement', 'image-snippets-gallery' ),
			'predicates' => array(),
		),
	);
}

/**
 * The default order the date sources are tried in.
 *
 * @return string[]
 */
function isgal_default_date_priority() {
	return array( 'DateTimeOriginal', 'CreateDate', 'DateCreated', 'rights' );
}

/**
 * The date an image should be shown and sorted with, per a priority list:
 * the first source that has a value wins.
 *
 * @param array         $row      Row.
 * @param string[]|null $priority Source keys in order; null for the default.
 * @return array|null [ 'raw' => as stored, 'source' => key, 'ts' => unix time or 0 ]
 */
function isgal_row_resolved_date( array $row, $priority = null ) {
	$priority = is_array( $priority ) && $priority ? $priority : isgal_default_date_priority();
	$dates    = isset( $row['dates'] ) && is_array( $row['dates'] ) ? $row['dates'] : array();
	// Rows mirrored before dates were kept separately: the one date they have.
	if ( empty( $dates ) && ! empty( $row['date'] ) ) {
		$dates['DateCreated'] = $row['date'];
	}
	foreach ( $priority as $key ) {
		$raw = '';
		if ( 'rights' === $key ) {
			$rights = isset( $row['rights'] ) ? (string) $row['rights'] : '';
			if ( preg_match( '/\b(1[89]\d{2}|20\d{2})\b/', $rights, $m ) ) {
				$raw = $m[1];
			}
		} elseif ( isset( $dates[ $key ] ) ) {
			$raw = trim( (string) $dates[ $key ] );
		}
		if ( '' === $raw ) {
			continue;
		}
		$ts = isgal_mirror_date_ts( $raw );
		if ( ! $ts && ! preg_match( '/^\d{4}$/', $raw ) ) {
			continue; // Unparseable: try the next source rather than show junk.
		}
		return array(
			'raw'    => $raw,
			'source' => $key,
			'ts'     => $ts,
		);
	}
	return null;
}

/**
 * Valid JSON-LD payload profiles.
 *
 * The editor exposes only two of these, as a toggle: 'provenance' (on) and
 * 'schema' (off). 'full' — 'provenance' plus the ImageSnippets page's own Open
 * Graph, Twitter and stylesheet triples, and EXIF where a graph has any — is
 * still accepted from saved blocks and from code, but is not offered in the UI:
 * on the corpus it adds page furniture, not provenance.
 *
 * @return array
 */
function isgal_jsonld_profiles() {
	return array( 'schema', 'provenance', 'full' );
}

/**
 * Normalize a requested profile.
 *
 * @param string $profile Requested profile.
 * @return string
 */
function isgal_resolve_profile( $profile ) {
	$profile = strtolower( trim( (string) $profile ) );
	return in_array( $profile, isgal_jsonld_profiles(), true ) ? $profile : 'provenance';
}

/**
 * SPARQL prefixes shared by both sync queries.
 *
 * @return string
 */
function isgal_sparql_prefixes() {
	// Concatenated rather than heredoc: the wp.org review tooling rejects
	// heredoc outright, so this stays a plain string.
	return "PREFIX dc: <http://purl.org/dc/elements/1.1/>\n"
		. "PREFIX dcterms: <http://purl.org/dc/terms/>\n"
		. "PREFIX lio: <https://w3id.org/lio/v1#>\n"
		. "PREFIX schema: <http://schema.org/>\n"
		. "PREFIX photoshop: <http://ns.adobe.com/photoshop/1.0/>\n";
}

/**
 * The gallery membership pattern. Isolated so that if ImageSnippets ever
 * offers a better predicate than lio:isIn, this is the one place to change.
 *
 * Confirmed against the live endpoint (2026-08-17): the only predicate whose
 * object is a dataset IRI is lio:isIn.
 *
 * @param string $gallery Gallery name (raw; sanitised here).
 * @return string SPARQL fragment binding ?image.
 */
function isgal_sparql_membership( $gallery ) {
	$dataset = isgal_dataset_iri( $gallery );
	return "?image lio:isIn <{$dataset}>.";
}

/**
 * The dataset IRI a gallery name refers to.
 *
 * ImageSnippets groups datasets by owner: datasets/{owner}/{gallery}. Most
 * galleries sit under "Imagesnippets", so a bare name ("ejwfeatured") is
 * looked up there. Galleries elsewhere are named with their owner:
 * "ejw_galleries/railroad_project". Anything after a second slash is
 * discarded rather than guessed at.
 *
 * @param string $gallery Gallery name, optionally "owner/gallery" (raw; sanitised here).
 * @return string Dataset IRI.
 */
function isgal_dataset_iri( $gallery ) {
	$parts = explode( '/', trim( (string) $gallery ), 3 );
	if ( count( $parts ) >= 2 && '' !== $parts[0] && '' !== $parts[1] ) {
		$owner = $parts[0];
		$name  = $parts[1];
	} else {
		$owner = ISGAL_DEFAULT_DATASET_OWNER;
		$name  = $parts[0];
	}
	return ISGAL_DATASET_BASE . isgal_sanitize_iri_segment( $owner ) . '/' . isgal_sanitize_iri_segment( $name );
}

/**
 * Listing query: every dataset that has at least one image with a thumbnail,
 * with its image count. Feeds the editor's gallery picker.
 *
 * The thumbnail condition matches the sync's, so the count shown when
 * choosing a gallery is the count the block will then show. lio:isIn also
 * points at things that are not datasets (DBpedia concepts, places, skolem
 * nodes); those are filtered out in PHP by IRI prefix, not here, so this
 * query stays cheap and the endpoint's regex support is not relied on.
 *
 * @return string
 */
function isgal_build_sparql_datasets() {
	return isgal_sparql_prefixes()
		. "SELECT ?ds (COUNT(DISTINCT ?image) AS ?n) WHERE {\n"
		. "  GRAPH ?page {\n"
		. "    ?image lio:isIn ?ds.\n"
		. "    ?image schema:thumbnail ?thumb.\n"
		. "  }\n"
		. "}\n"
		. "GROUP BY ?ds\n"
		. "ORDER BY ?ds\n"
		. 'LIMIT 2000';
}

/**
 * Sync query 1: every image in a gallery, with the fields the sync sorts and
 * labels by. Small rows, so it is unbounded except for a safety ceiling.
 *
 * @param string $gallery Gallery name.
 * @return string
 */
function isgal_build_sparql_list( $gallery ) {
	$member = isgal_sparql_membership( $gallery );
	$cap    = max( 1, (int) apply_filters( 'isgal_sync_max_images', ISGAL_SYNC_MAX_IMAGES ) );

	return isgal_sparql_prefixes()
		. "SELECT ?page ?image (SAMPLE(?d) AS ?date_) (SAMPLE(?t) AS ?title_) WHERE {\n"
		. "  GRAPH ?page {\n"
		. "    {$member}\n"
		. "    ?image schema:thumbnail ?thumb.\n"
		. "    optional { ?image photoshop:DateCreated ?d. }\n"
		. "    optional { ?image dc:title ?t. }\n"
		. "  }\n"
		. "}\n"
		. "GROUP BY ?page ?image\n"
		. "LIMIT {$cap}";
}

/**
 * Sync query 2: the whole named graph for a batch of images, untrimmed.
 *
 * Trimming happens at render, per payload profile, so the mirror holds every
 * triple ImageSnippets holds.
 *
 * @param array $pages Graph IRIs. Must already be checked with isgal_iri_is_clean().
 * @return string
 */
function isgal_build_sparql_graphs( array $pages ) {
	$values = '';
	foreach ( $pages as $page ) {
		$values .= '<' . $page . '> ';
	}

	return isgal_sparql_prefixes()
		. "SELECT ?page ?s ?p ?o WHERE {\n"
		. "  VALUES ?page { {$values}}\n"
		. "  GRAPH ?page { ?s ?p ?o. }\n"
		. '}';
}

/**
 * Whether a predicate is dropped by the trimming profiles.
 *
 * @param string $predicate Predicate IRI.
 * @return bool
 */
function isgal_predicate_is_trimmed( $predicate ) {
	static $prefixes = null;
	if ( null === $prefixes ) {
		$prefixes = isgal_graph_dropped_prefixes();
	}
	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $predicate, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Compact an IRI against the emitted @context.
 *
 * Longest namespace first, so http://schema.org/ does not shadow a longer
 * namespace that happens to share its opening characters.
 *
 * @param string $iri IRI.
 * @return string Compacted term, or the IRI unchanged.
 */
function isgal_compact_iri( $iri ) {
	static $sorted = null;

	if ( null === $sorted ) {
		// '@vocab' is a keyword, not a prefix: compacting against it would produce
		// "@vocab:name", which is not a term at all.
		$sorted = array_filter(
			isgal_jsonld_prefixes(),
			static function ( $prefix ) {
				return '@' !== $prefix[0];
			},
			ARRAY_FILTER_USE_KEY
		);
		uasort(
			$sorted,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
	}

	foreach ( $sorted as $prefix => $namespace ) {
		if ( 0 === strpos( $iri, $namespace ) ) {
			$local = substr( $iri, strlen( $namespace ) );
			// A local name containing a slash would not round-trip as a CURIE.
			if ( '' !== $local && false === strpos( $local, '/' ) ) {
				return $prefix . ':' . $local;
			}
		}
	}

	return $iri;
}

/**
 * Convert one SPARQL result term into its JSON-LD form.
 *
 * @param array $term SPARQL JSON term ('type', 'value', maybe 'xml:lang'/'datatype').
 * @return mixed
 */
function isgal_term_to_jsonld( array $term ) {
	$type  = isset( $term['type'] ) ? $term['type'] : 'literal';
	$value = isset( $term['value'] ) ? $term['value'] : '';

	if ( 'uri' === $type ) {
		return array( '@id' => isgal_compact_iri( $value ) );
	}

	if ( 'bnode' === $type ) {
		return array( '@id' => '_:' . $value );
	}

	if ( ! empty( $term['xml:lang'] ) ) {
		return array(
			'@value'    => $value,
			'@language' => $term['xml:lang'],
		);
	}

	// xsd:string is the default for a plain literal; saying so adds noise.
	if ( ! empty( $term['datatype'] ) && 'http://www.w3.org/2001/XMLSchema#string' !== $term['datatype'] ) {
		return array(
			'@value' => $value,
			'@type'  => isgal_compact_iri( $term['datatype'] ),
		);
	}

	return $value;
}

/**
 * Group flat ?page ?s ?p ?o bindings into rows, one per image.
 *
 * Returns the display keys the renderer expects, plus the graph itself and a
 * label lookup built from the rdfs:label statements that were sitting in the
 * graph all along.
 *
 * @param array $bindings SPARQL JSON bindings from the graph query.
 * @param array $meta     Map of graph IRI to array( image, date, title ) from
 *                        the list query.
 * @return array
 */
function isgal_parse_graph_bindings( array $bindings, array $meta = array() ) {
	$graphs = array();

	foreach ( $bindings as $binding ) {
		if ( ! isset( $binding['page']['value'], $binding['s']['value'], $binding['p']['value'], $binding['o'] ) ) {
			continue;
		}

		$page = $binding['page']['value'];

		if ( ! isset( $graphs[ $page ] ) ) {
			$m               = isset( $meta[ $page ] ) ? $meta[ $page ] : array();
			$graphs[ $page ] = array(
				'image'   => isset( $m['image'] ) ? $m['image'] : ( isset( $binding['image']['value'] ) ? $binding['image']['value'] : '' ),
				'date'    => isset( $m['date'] ) ? $m['date'] : '',
				'title'   => isset( $m['title'] ) ? $m['title'] : '',
				'triples' => array(),
				'labels'  => array(),
			);
		}

		$subject   = $binding['s']['value'];
		$predicate = $binding['p']['value'];

		$graphs[ $page ]['triples'][] = array( $subject, $predicate, $binding['o'] );

		if ( 'http://www.w3.org/2000/01/rdf-schema#label' === $predicate && 'literal' === $binding['o']['type'] ) {
			$graphs[ $page ]['labels'][ $subject ] = $binding['o']['value'];
		}
	}

	$rows = array();
	foreach ( $graphs as $page => $graph ) {
		$rows[] = isgal_graph_to_row( $page, $graph );
	}

	// Canonical order, not display order. Display order is a query on the
	// mirror; this only keeps the sync deterministic.
	usort(
		$rows,
		static function ( $x, $y ) {
			$by_date = strcmp( (string) $y['date'], (string) $x['date'] );
			return ( 0 !== $by_date ) ? $by_date : strcmp( (string) $x['image'], (string) $y['image'] );
		}
	);

	return $rows;
}

/**
 * Collapse one named graph into a display row.
 *
 * @param string $page  Named graph IRI.
 * @param array  $graph Parsed graph.
 * @return array
 */
function isgal_graph_to_row( $page, array $graph ) {
	$image = $graph['image'];

	// Only statements whose subject is the image itself describe the image. The
	// rest of the graph describes the ImageSnippets page, the creator, or the
	// entities depicted.
	$props = array();
	foreach ( $graph['triples'] as $triple ) {
		list( $subject, $predicate, $object ) = $triple;
		if ( $subject === $image ) {
			$props[ $predicate ][] = $object;
		}
	}

	$first = static function ( $predicate ) use ( $props ) {
		return isset( $props[ $predicate ][0]['value'] ) ? $props[ $predicate ][0]['value'] : '';
	};

	// Resolve any object that is a skolem or entity IRI to its label, so nothing
	// reaches the page as a bare identifier.
	$labelled = static function ( $predicate ) use ( $props, $graph ) {
		if ( ! isset( $props[ $predicate ][0] ) ) {
			return '';
		}
		$term  = $props[ $predicate ][0];
		$value = isset( $term['value'] ) ? $term['value'] : '';
		if ( 'uri' === $term['type'] && isset( $graph['labels'][ $value ] ) ) {
			return $graph['labels'][ $value ];
		}
		return $value;
	};

	// Subjects and objects across every "is about" predicate. IRIs become
	// entities with a resolved name; plain literals become keywords.
	$abouts   = array();
	$keywords = array();
	foreach ( isgal_graph_about_predicates() as $predicate ) {
		if ( empty( $props[ $predicate ] ) ) {
			continue;
		}
		foreach ( $props[ $predicate ] as $term ) {
			$value = isset( $term['value'] ) ? $term['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( 'uri' === $term['type'] ) {
				$abouts[ $value ] = isset( $graph['labels'][ $value ] ) ? $graph['labels'][ $value ] : '';
			} else {
				$keywords[ $value ] = true;
			}
		}
	}

	// Every date the graph offers, keyed by source. Which one counts is the
	// block's decision (isgal_row_resolved_date()); the mirror keeps them all.
	$dates = array();
	foreach ( isgal_date_sources() as $key => $source ) {
		foreach ( $source['predicates'] as $predicate ) {
			$value = $first( $predicate );
			if ( '' !== $value ) {
				$dates[ $key ] = $value;
				break;
			}
		}
	}

	$about_list = array();
	foreach ( $abouts as $id => $label ) {
		$about_list[] = array(
			'id'    => $id,
			'label' => $label,
		);
	}

	$row         = array(
		'image'    => $image,
		'page'     => $page,
		'thumb'    => $first( 'http://schema.org/thumbnail' ),
		'content'  => $first( 'http://schema.org/contentUrl' ),
		'title'    => $first( 'http://purl.org/dc/elements/1.1/title' ),
		'name'     => $first( 'http://schema.org/name' ),
		'desc'     => $first( 'http://www.iptc.org/std/Iptc4xmpCore/1.0/xmlns/ExtDescrAccessibility' ),
		'alt'      => $first( 'http://www.iptc.org/std/Iptc4xmpCore/1.0/xmlns/AltTextAccessibility' ),
		'date'     => '', // Filled below from the default date resolution.
		'dates'    => $dates,
		'rights'   => $labelled( 'http://purl.org/dc/elements/1.1/rights' ),
		'web'      => $first( 'http://ns.adobe.com/xap/1.0/rights/WebStatement' ),
		'licurl'   => $first( 'http://ns.useplus.org/ldf/xmp/1.0/LicensorURL' ),
		'location' => $first( 'https://w3id.org/lio/v1#hasSceneLocation' ),
		'creator'  => $labelled( 'http://purl.org/dc/elements/1.1/creator' ),
		'abouts'   => $about_list,
		'keywords' => array_keys( $keywords ),
		'triples'  => $graph['triples'],
		'labels'   => $graph['labels'],
	);
	$resolved    = isgal_row_resolved_date( $row );
	$row['date'] = $resolved ? $resolved['raw'] : '';
	return $row;
}

/**
 * A predicate as words: the local name, split where the case changes.
 * "hasInForeground" reads "Has in foreground", "DateCreated" "Date created".
 *
 * @param string $iri Predicate IRI.
 * @return string
 */
function isgal_predicate_label( $iri ) {
	if ( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' === $iri ) {
		return __( 'Type', 'image-snippets-gallery' );
	}
	$local = preg_replace( '~^.*[/#:]~', '', rtrim( (string) $iri, '/#' ) );
	$words = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|[_\-]+/', ' ', $local );
	$words = trim( (string) $words );
	if ( '' === $words ) {
		return (string) $iri;
	}
	// Leave an acronym alone ("URL"), lower the rest ("Date created").
	$parts = explode( ' ', $words );
	foreach ( $parts as $i => $part ) {
		if ( strtoupper( $part ) !== $part || 1 === strlen( $part ) ) {
			$parts[ $i ] = strtolower( $part );
		}
	}
	return ucfirst( implode( ' ', $parts ) );
}

/**
 * What to call a node of the graph that has no rdfs:label: a region by its
 * fragment ("Region A"), anything else by its last path segment.
 *
 * @param string $iri    Node IRI.
 * @param array  $labels The graph's label lookup.
 * @return string
 */
function isgal_node_label( $iri, array $labels ) {
	if ( isset( $labels[ $iri ] ) && '' !== trim( (string) $labels[ $iri ] ) ) {
		return trim( (string) $labels[ $iri ] );
	}
	$fragment = strpos( $iri, '#' );
	if ( false !== $fragment && strlen( $iri ) > $fragment + 1 ) {
		$name = rawurldecode( substr( $iri, $fragment + 1 ) );
		return str_replace( '_', ' ', preg_replace( '/^Region_/', '', $name ) );
	}
	return isgal_compact_iri( $iri );
}

/**
 * The back of the photograph: every statement in the image's graph, as words,
 * grouped by what it is about. The image comes first, then its regions and
 * whatever else the graph describes. This is what ImageSnippets shows when an
 * image page is flipped, and the lightbox shows it the same way.
 *
 * Left out: the Open Graph / Twitter / stylesheet statements, which describe
 * the ImageSnippets web page rather than the image; statements with an empty
 * value; and the rdfs:label statements, which are read here as the names of
 * the things they label instead of being listed beside them.
 *
 * @param array $row Row.
 * @return array List of groups: [ 's' => heading, 'rows' => [ [ 'p' => label,
 *               'i' => compact predicate, 'v' => [ [ 't' => text, 'h' => href ] ] ] ] ].
 */
function isgal_row_back( array $row ) {
	$labels = isset( $row['labels'] ) ? (array) $row['labels'] : array();
	$chrome = isgal_graph_chrome_prefixes();
	$groups = array();

	foreach ( (array) $row['triples'] as $triple ) {
		list( $subject, $predicate, $term ) = $triple;
		$value                              = isset( $term['value'] ) ? trim( (string) $term['value'] ) : '';
		if ( '' === $value || 'http://www.w3.org/2000/01/rdf-schema#label' === $predicate ) {
			continue;
		}
		foreach ( $chrome as $prefix ) {
			if ( 0 === strpos( $predicate, $prefix ) ) {
				continue 2;
			}
		}

		$type = isset( $term['type'] ) ? $term['type'] : 'literal';
		$text = 'uri' === $type ? isgal_node_label( $value, $labels ) : $value;
		$href = preg_match( '#^https?://\S+$#i', $value ) ? esc_url_raw( $value ) : '';

		if ( ! isset( $groups[ $subject ][ $predicate ] ) ) {
			$groups[ $subject ][ $predicate ] = array(
				'p' => isgal_predicate_label( $predicate ),
				'i' => isgal_compact_iri( $predicate ),
				'v' => array(),
			);
		}
		$groups[ $subject ][ $predicate ]['v'][ $text . '|' . $href ] = array(
			't' => $text,
			'h' => $href,
		);
	}

	// The image first; the rest in graph order.
	$image = (string) $row['image'];
	if ( isset( $groups[ $image ] ) ) {
		$groups = array( $image => $groups[ $image ] ) + $groups;
	}

	$back = array();
	foreach ( $groups as $subject => $predicates ) {
		$rows = array();
		foreach ( $predicates as $entry ) {
			$entry['v'] = array_values( $entry['v'] );
			$rows[]     = $entry;
		}
		if ( (string) $subject === $image ) {
			$heading = __( 'This image', 'image-snippets-gallery' );
		} elseif ( (string) $subject === (string) $row['page'] ) {
			$heading = __( 'This metadata record', 'image-snippets-gallery' );
		} else {
			$heading = isgal_node_label( (string) $subject, $labels );
		}
		$back[] = array(
			's'    => $heading,
			'rows' => $rows,
		);
	}
	return $back;
}

/**
 * The flat schema.org node for one image. This is the part Google reads.
 *
 * @param array $row          Row.
 * @param bool  $use_filename Filename fallback for names.
 * @return array
 */
function isgal_schema_node( array $row, $use_filename ) {
	$node = array(
		'@id'          => $row['image'],
		'@type'        => 'ImageObject',
		'contentUrl'   => $row['content'] ? $row['content'] : $row['thumb'],
		'thumbnailUrl' => $row['thumb'],
	);

	$name = isgal_row_title( $row, $use_filename );
	if ( '' !== $name ) {
		$node['name'] = $name;
	}

	$scalars = array(
		'description'        => 'desc',
		'dateCreated'        => 'date',
		'license'            => 'web',
		'acquireLicensePage' => 'licurl',
		'copyrightNotice'    => 'rights',
	);
	foreach ( $scalars as $term => $key ) {
		if ( '' !== $row[ $key ] ) {
			$node[ $term ] = $row[ $key ];
		}
	}

	if ( '' !== $row['creator'] ) {
		$node['creator'] = array(
			'@type' => 'Person',
			'name'  => $row['creator'],
		);
	}

	// The ImageSnippets page is the canonical description of this image.
	if ( '' !== $row['page'] ) {
		$node['subjectOf'] = array( '@id' => $row['page'] );
	}

	if ( '' !== $row['location'] ) {
		$node['contentLocation'] = array( '@id' => isgal_compact_iri( $row['location'] ) );
	}

	// Every entity carries the name resolved from its own graph, so none of this
	// reaches a crawler as a bare identifier.
	if ( ! empty( $row['abouts'] ) ) {
		$about = array();
		foreach ( $row['abouts'] as $entity ) {
			$item = array( '@id' => isgal_compact_iri( $entity['id'] ) );
			if ( '' !== $entity['label'] ) {
				$item['name'] = $entity['label'];
			}
			$about[] = $item;
		}
		$node['about'] = $about;
	}

	if ( ! empty( $row['keywords'] ) ) {
		$node['keywords'] = $row['keywords'];
	}

	return $node;
}

/**
 * The named-graph object for one image: its triples, verbatim, still attributed
 * to the graph that asserted them.
 *
 * The mirror stores every triple. The 'provenance' profile drops the page
 * furniture and camera fields here, at render; 'full' passes it all through.
 *
 * @param array  $row     Row.
 * @param string $profile Resolved payload profile.
 * @return array
 */
function isgal_named_graph_node( array $row, $profile = 'provenance' ) {
	$subjects = array();
	$trim     = ( 'full' !== $profile );

	foreach ( $row['triples'] as $triple ) {
		list( $subject, $predicate, $object ) = $triple;

		if ( $trim && isgal_predicate_is_trimmed( $predicate ) ) {
			continue;
		}

		if ( ! isset( $subjects[ $subject ] ) ) {
			$subjects[ $subject ] = array( '@id' => isgal_compact_iri( $subject ) );
		}

		$term  = isgal_compact_iri( $predicate );
		$value = isgal_term_to_jsonld( $object );

		// rdf:type is @type in JSON-LD, and its object is always an identifier.
		if ( 'rdf:type' === $term ) {
			$term  = '@type';
			$value = isset( $value['@id'] ) ? $value['@id'] : $value;
		}

		if ( ! isset( $subjects[ $subject ][ $term ] ) ) {
			$subjects[ $subject ][ $term ] = $value;
		} elseif ( is_array( $subjects[ $subject ][ $term ] ) && isset( $subjects[ $subject ][ $term ][0] ) ) {
			$subjects[ $subject ][ $term ][] = $value;
		} else {
			$subjects[ $subject ][ $term ] = array( $subjects[ $subject ][ $term ], $value );
		}
	}

	return array(
		'@id'    => $row['page'],
		'@graph' => array_values( $subjects ),
	);
}

/**
 * The URL of the page being rendered, for hanging the gallery node on.
 *
 * Empty inside the block-editor preview, where there is no front-end URL yet.
 * An anonymous gallery node is still valid JSON-LD, so this degrades quietly.
 *
 * @return string
 */
function isgal_current_permalink() {
	if ( is_singular() ) {
		$permalink = get_permalink();
		return $permalink ? $permalink : '';
	}
	return '';
}

/**
 * Build the gallery's JSON-LD.
 *
 * @param array  $rows         Rows.
 * @param string $gallery      Gallery name.
 * @param bool   $use_filename Filename fallback for names.
 * @param string $profile      Payload profile.
 * @param string $base_id      IRI to hang the gallery node on.
 * @return string <script> tag, or empty string.
 */
function isgal_jsonld( array $rows, $gallery, $use_filename, $profile = 'provenance', $base_id = '' ) {
	if ( empty( $rows ) ) {
		return '';
	}

	$profile = isgal_resolve_profile( $profile );

	$gallery_node = array(
		'@type' => 'ImageGallery',
		'name'  => $gallery,
	);
	if ( '' !== $base_id ) {
		$gallery_node['@id'] = $base_id . '#gallery';
	}

	$images = array();
	$nodes  = array();
	$named  = array();

	foreach ( $rows as $row ) {
		if ( '' === $row['image'] ) {
			continue;
		}
		$images[] = array( '@id' => $row['image'] );
		$nodes[]  = isgal_schema_node( $row, $use_filename );

		if ( 'schema' !== $profile ) {
			$named[] = isgal_named_graph_node( $row, $profile );
		}
	}

	$gallery_node['image'] = $images;

	// One default graph holding the schema.org projection, then the named graphs
	// beside it. A consumer that only knows schema.org reads the first part and
	// ignores the rest; an RDF consumer gets both, with attribution intact.
	$payload = array(
		'@context' => isgal_jsonld_prefixes(),
		'@graph'   => array_merge( array( $gallery_node ), $nodes, $named ),
	);

	/**
	 * Filters the JSON-LD payload before it is encoded.
	 *
	 * @param array  $payload Payload.
	 * @param array  $rows    Rows it was built from.
	 * @param string $profile Resolved profile.
	 */
	$payload = apply_filters( 'isgal_jsonld_payload', $payload, $rows, $profile );

	return '<script type="application/ld+json">'
		. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>';
}
