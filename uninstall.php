<?php
/**
 * Uninstall: remove everything the plugin stored.
 *
 * The mirror is invisible in wp-admin, so nobody could clean it up by hand.
 * Deleting the plugin has to leave the database exactly as it found it.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Mirrored images and gallery labels.
$isg_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'isg_image'" );
foreach ( (array) $isg_ids as $isg_id ) {
	wp_delete_post( (int) $isg_id, true );
}
$isg_terms = $wpdb->get_results(
	"SELECT tt.term_id, tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'isg_gallery'"
);
foreach ( (array) $isg_terms as $isg_term ) {
	// The taxonomy is not registered during uninstall, so go direct.
	$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $isg_term->term_taxonomy_id ) );
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $isg_term->term_taxonomy_id ) );
	$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $isg_term->term_id ) );
	$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $isg_term->term_id ) );
}

// The block index.
delete_post_meta_by_key( '_isg_gallery' );

// Options and transients.
delete_option( 'isg_sync_status' );
delete_option( 'isg_purge_adapters' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_isg%' OR option_name LIKE '\\_transient\\_timeout\\_isg%'" );

// Scheduled syncs.
wp_unschedule_hook( 'isg_sync_gallery' );
wp_unschedule_hook( 'isg_refresh_cache' );
