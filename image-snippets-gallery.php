<?php
/**
 * Plugin Name:       ImageSnippets Gallery
 * Plugin URI:        https://imagesnippets.com/
 * Description:        Responsive, server-rendered gallery of images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability. Galleries are mirrored into WordPress, so pages render without waiting on the network and site search finds the images. A modern fork of "IS Gallery" by Henry Sautter.
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Version:           0.4.0
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

define( 'ISG_VERSION', '0.4.0' );
define( 'ISG_DEFAULT_ENDPOINT', 'https://imagesnippets.com/sparql/dbpedia' );
define( 'ISG_DATASET_BASE', 'https://imagesnippets.com/imgtag/datasets/Imagesnippets/' );
define( 'ISG_USER_BASE', 'https://imagesnippets.com/imgtag/users/' );
// Cap for block-editor previews, in seconds. Short enough to read as live while
// still absorbing the burst of re-renders ServerSideRender fires as you drag a slider.
define( 'ISG_EDITOR_TTL', 15 );
// How long a queued background refresh may sit unclaimed before we conclude
// WP-Cron is not dispatching and do the work in the foreground instead.
define( 'ISG_CRON_STALL_MARGIN', 120 );

require_once __DIR__ . '/includes/cache-purge.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/graph.php';
require_once __DIR__ . '/includes/mirror.php';
require_once __DIR__ . '/includes/query.php';
require_once __DIR__ . '/includes/rest.php';

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
function isg_activate() {
	isg_rebuild_index();
}
register_activation_hook( __FILE__, 'isg_activate' );

/**
 * Register the block from build/block.json. The block is dynamic; its server
 * render lives in build/render.php (copied from src/render.php at build time).
 */
function isg_register_block() {
	register_block_type( __DIR__ . '/build' );
}
add_action( 'init', 'isg_register_block' );
