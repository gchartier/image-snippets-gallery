<?php
/**
 * WP-CLI: `wp isgal`.
 *
 * For hosts where cron is unreliable, for scripting a sync after a bulk change
 * on ImageSnippets, and for seeing what the mirror holds without opening
 * wp-admin.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the ImageSnippets gallery mirror.
 */
class ISGAL_CLI_Command {

	/**
	 * Synchronise galleries from ImageSnippets into the mirror.
	 *
	 * ## OPTIONS
	 *
	 * [<gallery>]
	 * : Gallery name. Omit to sync every gallery in use on the site.
	 *
	 * [--endpoint=<url>]
	 * : SPARQL endpoint. Defaults to the ImageSnippets endpoint.
	 *
	 * [--cron]
	 * : Sync as cron would: purge page caches only if something changed, and
	 * refuse an empty result for a gallery that had images. Without it, this
	 * behaves like the Refresh button: always purge, accept what comes back.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isgal sync
	 *     wp isgal sync hs_gallery02
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ) {
		$force = empty( $assoc_args['cron'] );

		if ( ! empty( $args[0] ) ) {
			$endpoint = ! empty( $assoc_args['endpoint'] ) ? esc_url_raw( $assoc_args['endpoint'] ) : isgal_default_endpoint();
			$result   = $force
				? isgal_refresh_gallery( $endpoint, $args[0] )
				: isgal_sync_gallery( $endpoint, $args[0], array( 'timeout' => 20 ) );
			$this->report( $args[0], $result );
			return;
		}

		if ( $force ) {
			$results = isgal_refresh_all_galleries();
		} else {
			$results = array();
			$done    = array();
			foreach ( isgal_indexed_gallery_blocks() as $block ) {
				$a   = isgal_resolve_attributes( $block['attrs'] );
				$key = isgal_resolve_endpoint( $a ) . '|' . $block['gallery'];
				if ( isset( $done[ $key ] ) ) {
					continue;
				}
				$done[ $key ]                 = true;
				$results[ $block['gallery'] ] = isgal_sync_gallery( isgal_resolve_endpoint( $a ), $block['gallery'], array( 'timeout' => 20 ) );
			}
		}

		if ( empty( $results ) ) {
			WP_CLI::warning( 'No galleries are in use on this site. Add a gallery block to a page first, or run `wp isgal reindex`.' );
			return;
		}
		$failed = 0;
		foreach ( $results as $gallery => $result ) {
			if ( ! $this->report( $gallery, $result ) ) {
				++$failed;
			}
		}
		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d galleries failed.', $failed, count( $results ) ) );
		}
		WP_CLI::success( sprintf( 'Synced %d galleries.', count( $results ) ) );
	}

	/**
	 * Saved sources: galleries made of a query instead of a dataset.
	 *
	 * ## OPTIONS
	 *
	 * <verb>
	 * : list, add, test or rm.
	 *
	 * [<name>]
	 * : For add, the label ("Ospreys"). For rm, the gallery name ("~ospreys").
	 *
	 * [--where=<pattern>]
	 * : For add and test, the SPARQL pattern binding ?image.
	 *
	 * ## EXAMPLES
	 *
	 *     wp isgal source list
	 *     wp isgal source test --where='?image lio:depicts dbr:Osprey.'
	 *     wp isgal source add Ospreys --where='?image lio:depicts dbr:Osprey.'
	 *     wp isgal source rm '~ospreys'
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function source( $args, $assoc_args ) {
		$verb  = isset( $args[0] ) ? $args[0] : 'list';
		$where = isset( $assoc_args['where'] ) ? (string) $assoc_args['where'] : '';

		if ( 'list' === $verb ) {
			$rows = array();
			foreach ( isgal_list_sources() as $source ) {
				$rows[] = array(
					'name'   => $source['name'],
					'label'  => $source['label'],
					'images' => $source['images'],
					'where'  => $source['where'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'label', 'images', 'where' ) );
			return;
		}

		if ( 'test' === $verb ) {
			$result = isgal_test_source( $where );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%d images in %ss%s', $result['count'], $result['seconds'], $result['capped'] ? ' (at the ceiling)' : '' ) );
			return;
		}

		if ( 'add' === $verb ) {
			$name = isgal_save_source( isset( $args[1] ) ? $args[1] : '', $where );
			if ( is_wp_error( $name ) ) {
				WP_CLI::error( $name->get_error_message() );
			}
			WP_CLI::log( sprintf( 'Saved as %s', $name ) );
			$this->report( $name, isgal_refresh_gallery( isgal_default_endpoint(), $name ) );
			return;
		}

		if ( 'rm' === $verb ) {
			if ( ! isgal_delete_source( isset( $args[1] ) ? $args[1] : '' ) ) {
				WP_CLI::error( 'No such source.' );
			}
			WP_CLI::success( 'Deleted.' );
			return;
		}

		WP_CLI::error( 'Unknown verb. Use list, add, test or rm.' );
	}

	/**
	 * Show what the mirror holds and when each gallery last synced.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default table.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$rows   = array();
		$status = isgal_sync_status();
		$terms  = get_terms(
			array(
				'taxonomy'   => ISGAL_TAXONOMY,
				'hide_empty' => false,
			)
		);
		$terms  = is_wp_error( $terms ) ? array() : $terms;
		$seen   = array();

		foreach ( $terms as $term ) {
			$gallery          = (string) get_term_meta( $term->term_id, ISGAL_TERM_GALLERY, true );
			$gallery          = '' !== $gallery ? $gallery : $term->name;
			$synced           = isgal_gallery_synced_at( $term );
			$s                = isset( $status[ $gallery ] ) ? $status[ $gallery ] : array();
			$rows[]           = array(
				'gallery'   => $gallery,
				'endpoint'  => (string) get_term_meta( $term->term_id, ISGAL_TERM_ENDPOINT, true ),
				'mirrored'  => (int) $term->count,
				'last_sync' => $synced ? gmdate( 'Y-m-d H:i:s', $synced ) . ' UTC' : 'never',
				'pages'     => count( isgal_posts_for_gallery( $gallery ) ),
				'error'     => isset( $s['error'] ) ? (string) $s['error'] : '',
			);
			$seen[ $gallery ] = true;
		}
		foreach ( isgal_indexed_galleries() as $gallery ) {
			if ( isset( $seen[ $gallery ] ) ) {
				continue;
			}
			$rows[] = array(
				'gallery'   => $gallery,
				'endpoint'  => '',
				'mirrored'  => 0,
				'last_sync' => 'never',
				'pages'     => count( isgal_posts_for_gallery( $gallery ) ),
				'error'     => '',
			);
		}

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'gallery', 'mirrored', 'pages', 'last_sync', 'error', 'endpoint' )
		);
	}

	/**
	 * Drop mirrored galleries that no page uses any more.
	 */
	public function prune() {
		$dropped = isgal_prune_mirror();
		WP_CLI::success( $dropped ? 'Dropped: ' . implode( ', ', $dropped ) : 'Nothing to prune.' );
	}

	/**
	 * Rebuild the index of which pages show which galleries.
	 */
	public function reindex() {
		WP_CLI::success( sprintf( '%d posts contain a gallery.', isgal_rebuild_index() ) );
	}

	/**
	 * Empty the mirror completely. It is rebuilt on the next page view or sync.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete every mirrored image and gallery label?', $assoc_args );
		WP_CLI::success( sprintf( 'Deleted %d mirrored images.', isgal_mirror_drop_all() ) );
	}

	/**
	 * Print one sync result.
	 *
	 * @param string         $gallery Gallery name.
	 * @param array|WP_Error $result  Sync summary.
	 * @return bool Whether it succeeded.
	 */
	private function report( $gallery, $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( sprintf( '%s: %s', $gallery, $result->get_error_message() ) );
			return false;
		}
		WP_CLI::log(
			sprintf(
				'%s: %d images (+%d ~%d -%d)%s%s',
				$gallery,
				$result['images'],
				$result['added'],
				$result['updated'],
				$result['removed'],
				$result['changed'] ? ', changed' : ', unchanged',
				$result['purged'] ? ', purged via ' . implode( ', ', $result['purged'] ) : ''
			)
		);
		return true;
	}
}

WP_CLI::add_command( 'isgal', 'ISGAL_CLI_Command' );
