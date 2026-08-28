<?php
/**
 * Portable page-cache invalidation.
 *
 * A gallery's contents change when data on ImageSnippets changes, which never
 * touches a post. Page caches invalidate on post changes, so without this the
 * stored HTML outlives every refresh the plugin performs underneath it.
 *
 * There is no universal purge API in WordPress, so this works in three layers:
 *
 *   1. The core convention. Most caches hook post-change events, so telling
 *      WordPress the post changed invalidates them with no integration code.
 *   2. Feature-detected adapters. Detection is against the installed plugins,
 *      never against a host name — nothing here is specific to one host.
 *   3. A documented action, because no list of cache plugins stays complete.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapters that can purge a single post. Preferred over the site-wide list:
 * flushing an entire cache to update one gallery is rude on a busy site.
 *
 * Each entry is array( label, kind, callable-or-action ). 'url' adapters take a
 * permalink instead of an ID. A callable may be a plain function name or a
 * 'Class::method' string — Cache Enabler exposes only static methods, with no
 * global function wrappers at all.
 *
 * @return array
 */
function isgal_page_cache_adapters_post() {
	return array(
		array( 'WP Rocket', 'fn', 'rocket_clean_post' ),
		array( 'W3 Total Cache', 'fn', 'w3tc_flush_post' ),
		array( 'Cache Enabler', 'fn', 'Cache_Enabler::clear_page_cache_by_post_id' ),
		array( 'WP Super Cache', 'fn', 'wp_cache_post_change' ),
		array( 'LiteSpeed Cache', 'action', 'litespeed_purge_post' ),
		array( 'Cache Enabler (by URL)', 'url', 'Cache_Enabler::clear_page_cache_by_url' ),
	);
}

/**
 * Adapters that can only flush everything. Used when no per-post adapter matched.
 *
 * @return array
 */
function isgal_page_cache_adapters_site() {
	return array(
		array( 'WP Super Cache', 'fn', 'wp_cache_clear_cache' ),
		array( 'W3 Total Cache', 'fn', 'w3tc_flush_all' ),
		array( 'WP Rocket', 'fn', 'rocket_clean_domain' ),
		array( 'Cache Enabler', 'fn', 'Cache_Enabler::clear_complete_cache' ),
		array( 'LiteSpeed Cache', 'action', 'litespeed_purge_all' ),
		array( 'Cachify', 'action', 'cachify_flush_cache' ),
		array( 'SG Optimizer', 'action', 'sg_cachepress_purge_cache' ),
	);
}

/**
 * Run one adapter. Returns its label if it fired, or '' if it is not installed.
 *
 * @param array      $adapter Adapter tuple.
 * @param int|string $arg     Post ID, or permalink for 'url' adapters.
 * @return string
 */
function isgal_run_page_cache_adapter( array $adapter, $arg = null ) {
	list( $label, $kind, $target ) = $adapter;

	if ( 'action' === $kind ) {
		// has_action() is the only honest test: an action with no listener is a
		// no-op, and firing it blindly would report a purge that never happened.
		if ( ! has_action( $target ) ) {
			return '';
		}
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- These are other plugins' purge actions (e.g. wp_cache_clear_cache), fired on purpose.
		if ( null === $arg ) {
			do_action( $target );
		} else {
			do_action( $target, $arg );
		}
		// phpcs:enable
		return $label;
	}

	// is_callable() rather than function_exists() so a 'Class::method' target
	// resolves too. Several caches — Cache Enabler among them — publish their
	// purge API only as static methods.
	if ( ! is_callable( $target ) ) {
		return '';
	}
	if ( null === $arg ) {
		call_user_func( $target );
	} else {
		call_user_func( $target, $arg );
	}
	return $label;
}

/**
 * Invalidate any page cache holding the given posts.
 *
 * @param array $post_ids Posts whose rendered HTML is now out of date.
 * @return array Labels of the adapters that fired.
 */
function isgal_purge_page_cache( array $post_ids ) {
	$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

	$fired = array();

	// Layer 1: tell WordPress the post changed. Anything following core
	// conventions invalidates itself from here, with no adapter needed.
	foreach ( $post_ids as $post_id ) {
		clean_post_cache( $post_id );
	}

	// Layer 2a: targeted purges.
	foreach ( $post_ids as $post_id ) {
		foreach ( isgal_page_cache_adapters_post() as $adapter ) {
			$arg   = ( 'url' === $adapter[1] ) ? get_permalink( $post_id ) : $post_id;
			$label = $arg ? isgal_run_page_cache_adapter( $adapter, $arg ) : '';
			if ( '' !== $label ) {
				$fired[ $label ] = true;
			}
		}
	}

	// Layer 2b: only if nothing could be purged precisely. Flushing a whole
	// site cache is a blunt instrument and should stay the last resort.
	if ( empty( $fired ) ) {
		foreach ( isgal_page_cache_adapters_site() as $adapter ) {
			$label = isgal_run_page_cache_adapter( $adapter );
			if ( '' !== $label ) {
				$fired[ $label ] = true;
			}
		}
	}

	$fired = array_keys( $fired );

	/**
	 * Fires when a gallery's contents have changed and its pages need
	 * invalidating. Layer 3: the escape hatch for any cache the adapters above
	 * do not know about, including host-level and CDN caches.
	 *
	 * @param array $post_ids Posts whose rendered HTML is now out of date.
	 * @param array $fired    Labels of the adapters that already ran.
	 */
	do_action( 'isgal_gallery_changed', $post_ids, $fired );

	if ( ! empty( $fired ) ) {
		update_option( 'isgal_purge_adapters', $fired, false );
	}

	return $fired;
}

/**
 * Whether a page cache appears to be installed at all.
 *
 * WP_CACHE plus an advanced-cache.php drop-in is the portable signal. It cannot
 * name the cache, and it cannot see a cache that lives in front of PHP entirely
 * (a CDN, or a host's reverse proxy) — which is exactly why layer 3 exists.
 *
 * @return bool
 */
function isgal_page_cache_detected() {
	if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
		return true;
	}
	return file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
}

/**
 * Adapter labels known to work on this site, recorded from the last purge.
 *
 * @return array
 */
function isgal_known_purge_adapters() {
	$stored = get_option( 'isgal_purge_adapters', array() );
	return is_array( $stored ) ? $stored : array();
}
