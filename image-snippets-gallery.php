<?php
/**
 * Plugin Name:       ImageSnippets Gallery
 * Plugin URI:        https://imagesnippets.com/
 * Description:        Responsive, server-rendered gallery of images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability. Galleries are mirrored into WordPress, so pages render without waiting on the network and site search finds the images. A modern fork of "IS Gallery" by Henry Sautter.
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Version:           0.7.0
 * Author:            GnoSys Labs
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-snippets-gallery
 *
 * Forked from "IS Gallery" (https://wordpress.org/plugins/is-gallery/),
 * Copyright 2021 Henry Sautter, GPL-2.0-or-later. With thanks.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ISGAL_VERSION', '0.7.0' );
define( 'ISGAL_PLUGIN_FILE', __FILE__ );
// "image-snippets-gallery/image-snippets-gallery.php": how WordPress identifies
// this plugin in per-plugin hooks such as plugin_action_links_{basename}.
define( 'ISGAL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ISGAL_DEFAULT_ENDPOINT', 'https://imagesnippets.com/sparql/dbpedia' );
define( 'ISGAL_DATASET_BASE', 'https://imagesnippets.com/imgtag/datasets/' );
// Datasets are grouped by owner: datasets/{owner}/{gallery}. A gallery name
// without an owner is looked up under this one, which holds most galleries.
define( 'ISGAL_DEFAULT_DATASET_OWNER', 'Imagesnippets' );
define( 'ISGAL_USER_BASE', 'https://imagesnippets.com/imgtag/users/' );
// Cap for block-editor previews, in seconds. Short enough to read as live while
// still absorbing the burst of re-renders ServerSideRender fires as you drag a slider.
define( 'ISGAL_EDITOR_TTL', 15 );
// How long a queued background refresh may sit unclaimed before we conclude
// WP-Cron is not dispatching and do the work in the foreground instead.
define( 'ISGAL_CRON_STALL_MARGIN', 120 );

require_once __DIR__ . '/includes/cache-purge.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/mirror.php';
require_once __DIR__ . '/includes/sources.php';
require_once __DIR__ . '/includes/query.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/opengraph.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/patterns.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}


/**
 * Build the gallery index on activation. Posts saved before this version never
 * fired the save_post hook that maintains it, so without this first pass the
 * plugin cannot tell which pages to invalidate.
 *
 * @return void
 */
function isgal_activate() {
	isgal_migrate_from_isg();
	isgal_rebuild_index();
	// Shows the getting-started notice once; Tools → ImageSnippets clears it.
	if ( false === get_option( 'isgal_welcome' ) ) {
		add_option( 'isgal_welcome', 1, '', false );
	}
}
register_activation_hook( __FILE__, 'isgal_activate' );

/**
 * Deactivation: stop the background refreshes. The mirror stays (it is data
 * a reactivation can use); uninstall.php removes it.
 *
 * @return void
 */
function isgal_deactivate() {
	wp_unschedule_hook( 'isgal_sync_gallery' );
}
register_deactivation_hook( __FILE__, 'isgal_deactivate' );

/**
 * One-time move from the pre-0.7 `isg` prefix to `isgal`.
 *
 * Everything stored under the old prefix is regenerable except the two site
 * defaults and the manual orders, which are carried over. Old mirror posts
 * and gallery terms are removed rather than renamed: the next page view (or
 * `wp isgal sync`) rebuilds the mirror from ImageSnippets.
 *
 * Runs on activation and, for sites that update without deactivating, once on
 * the first request that notices the stored version is older.
 *
 * @return void
 */
function isgal_migrate_from_isg() {
	if ( get_option( 'isgal_schema' ) === '1' ) {
		return;
	}
	global $wpdb;

	foreach ( array( 'default_endpoint', 'default_ttl', 'purge_adapters' ) as $name ) {
		$old = get_option( 'isg_' . $name, null );
		if ( null !== $old ) {
			if ( null === get_option( 'isgal_' . $name, null ) ) {
				update_option( 'isgal_' . $name, $old, false );
			}
			delete_option( 'isg_' . $name );
		}
	}
	delete_option( 'isg_sync_status' );

	// Manual orders were term meta on the old taxonomy; keep them by gallery name.
	$orders = array();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The old post type and taxonomy are no longer registered, so the API cannot see them.
	$old_terms = $wpdb->get_results(
		"SELECT tt.term_id, tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'isg_gallery'"
	);
	foreach ( (array) $old_terms as $term ) {
		$endpoint = get_term_meta( $term->term_id, 'isg_endpoint', true );
		$gallery  = get_term_meta( $term->term_id, 'isg_gallery', true );
		$order    = get_term_meta( $term->term_id, 'isg_manual_order', true );
		if ( $gallery && $order ) {
			$orders[] = array( $endpoint, $gallery, $order );
		}
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ) );
		$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $term->term_id ) );
		$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term->term_id ) );
	}
	$old_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'isg_image'" );
	foreach ( (array) $old_ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_isg_gallery'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_isg3lock%' OR option_name LIKE '\\_transient\\_timeout\\_isg3lock%' OR option_name LIKE '\\_transient\\_isg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_isg\\_%'" );
	// phpcs:enable
	wp_unschedule_hook( 'isg_sync_gallery' );
	wp_unschedule_hook( 'isg_refresh_cache' );

	update_option( 'isgal_schema', '1', false );
	if ( $orders ) {
		update_option( 'isgal_pending_orders', $orders, false );
	}
}
add_action( 'init', 'isgal_migrate_from_isg', 5 );

/**
 * Register the block from build/block.json. The block is dynamic; its server
 * render lives in build/render.php (copied from src/render.php at build time).
 */
function isgal_register_block() {
	register_block_type( __DIR__ . '/build' );
}
add_action( 'init', 'isgal_register_block' );
