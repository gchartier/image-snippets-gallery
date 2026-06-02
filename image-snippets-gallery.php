<?php
/**
 * Plugin Name:       ImageSnippets Gallery
 * Plugin URI:        https://imagesnippets.com/
 * Description:        Responsive, server-rendered gallery of images from ImageSnippets, with embedded provenance metadata (JSON-LD) for SEO and discoverability. A modern fork of "IS Gallery" by Henry Sautter.
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Version:           0.2.0
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

define( 'ISG_VERSION', '0.2.0' );
define( 'ISG_DEFAULT_ENDPOINT', 'https://imagesnippets.com/sparql/dbpedia' );
define( 'ISG_DATASET_BASE', 'https://imagesnippets.com/imgtag/datasets/Imagesnippets/' );
define( 'ISG_USER_BASE', 'https://imagesnippets.com/imgtag/users/' );

require_once __DIR__ . '/includes/query.php';

/**
 * Register the block from build/block.json. The block is dynamic; its server
 * render lives in build/render.php (copied from src/render.php at build time).
 */
function isg_register_block() {
	register_block_type( __DIR__ . '/build' );
}
add_action( 'init', 'isg_register_block' );
