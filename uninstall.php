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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall must remove rows regardless of any cache; the post type and taxonomy are not registered here, so the API cannot see them.

global $wpdb;

// Mirrored images and gallery labels.
$isgal_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'isgal_image'" );
foreach ( (array) $isgal_ids as $isgal_id ) {
	wp_delete_post( (int) $isgal_id, true );
}
$isgal_terms = $wpdb->get_results(
	"SELECT tt.term_id, tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'isgal_gallery'"
);
foreach ( (array) $isgal_terms as $isgal_term ) {
	// The taxonomy is not registered during uninstall, so go direct.
	$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $isgal_term->term_taxonomy_id ) );
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $isgal_term->term_taxonomy_id ) );
	$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $isgal_term->term_id ) );
	$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $isgal_term->term_id ) );
}

// The block index.
delete_post_meta_by_key( '_isgal_gallery' );

// Options and transients.
delete_option( 'isgal_sync_status' );
delete_option( 'isgal_purge_adapters' );
delete_option( 'isgal_default_endpoint' );
delete_option( 'isgal_default_ttl' );
delete_option( 'isgal_schema' );
delete_option( 'isgal_pending_orders' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_isgal%' OR option_name LIKE '\\_transient\\_timeout\\_isgal%'" );

// Scheduled syncs.
wp_unschedule_hook( 'isgal_sync_gallery' );
wp_unschedule_hook( 'isgal_refresh_cache' );
