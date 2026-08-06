<?php
/**
 * Tools screen and Site Health tests.
 *
 * The original bug report was "I add new images and it doesn't seem to be
 * updating....is there any reason that this is happening? or is it on my end".
 * That is a diagnosability failure as much as a caching one, so this screen
 * exists to answer it at a glance: when each gallery last synced, how many
 * images it holds, what went wrong if anything, and whether a page cache sits
 * in front that we may or may not be able to clear.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Tools submenu.
 *
 * @return void
 */
function isg_admin_menu() {
	add_management_page(
		__( 'ImageSnippets Galleries', 'image-snippets-gallery' ),
		__( 'ImageSnippets', 'image-snippets-gallery' ),
		'edit_posts',
		'isg-galleries',
		'isg_render_admin_page'
	);
}
add_action( 'admin_menu', 'isg_admin_menu' );

/**
 * Handle the screen's two actions before anything renders.
 *
 * @return void
 */
function isg_handle_admin_actions() {
	if ( ! isset( $_POST['isg_action'] ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['isg_action'] ) );
	check_admin_referer( 'isg_admin_' . $action );

	if ( 'refresh_all' === $action ) {
		$results = isg_refresh_all_galleries();
		$failed  = 0;
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				++$failed;
			}
		}
		add_settings_error(
			'isg',
			'isg_refreshed',
			$failed
				? sprintf(
					/* translators: 1: number refreshed, 2: number that failed */
					__( 'Refreshed %1$d galleries. %2$d could not be reached.', 'image-snippets-gallery' ),
					count( $results ) - $failed,
					$failed
				)
				: sprintf(
					/* translators: %d: number of galleries */
					__( 'Refreshed %d galleries.', 'image-snippets-gallery' ),
					count( $results )
				),
			$failed ? 'warning' : 'success'
		);
	}

	if ( 'rebuild_index' === $action ) {
		$count = isg_rebuild_index();
		add_settings_error(
			'isg',
			'isg_reindexed',
			sprintf(
				/* translators: %d: number of posts */
				__( 'Rebuilt the index. %d posts contain a gallery.', 'image-snippets-gallery' ),
				$count
			),
			'success'
		);
	}
}
add_action( 'load-tools_page_isg-galleries', 'isg_handle_admin_actions' );

/**
 * A submit button wrapped in its own form and nonce.
 *
 * @param string $action Action key.
 * @param string $label  Button label.
 * @param string $class  Button class.
 * @return void
 */
function isg_action_button( $action, $label, $class = 'button' ) {
	?>
	<form method="post" style="display:inline-block;margin-right:.5em;">
		<?php wp_nonce_field( 'isg_admin_' . $action ); ?>
		<input type="hidden" name="isg_action" value="<?php echo esc_attr( $action ); ?>">
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

/**
 * Render the Tools screen.
 *
 * @return void
 */
function isg_render_admin_page() {
	$galleries = isg_indexed_galleries();
	$status    = isg_sync_status();
	$adapters  = isg_known_purge_adapters();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ImageSnippets Galleries', 'image-snippets-gallery' ); ?></h1>

		<?php settings_errors( 'isg' ); ?>

		<p>
			<?php isg_action_button( 'refresh_all', __( 'Refresh all galleries', 'image-snippets-gallery' ), 'button button-primary' ); ?>
			<?php isg_action_button( 'rebuild_index', __( 'Rebuild index', 'image-snippets-gallery' ) ); ?>
		</p>

		<?php if ( empty( $galleries ) ) : ?>
			<p><?php esc_html_e( 'No galleries found. Add an ImageSnippets Gallery block to a published page, or rebuild the index if you added one before installing this version.', 'image-snippets-gallery' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Gallery', 'image-snippets-gallery' ); ?></th>
						<th><?php esc_html_e( 'Images', 'image-snippets-gallery' ); ?></th>
						<th><?php esc_html_e( 'Last updated', 'image-snippets-gallery' ); ?></th>
						<th><?php esc_html_e( 'Shown on', 'image-snippets-gallery' ); ?></th>
						<th><?php esc_html_e( 'Status', 'image-snippets-gallery' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $galleries as $gallery ) : ?>
					<?php
					$row   = isset( $status[ $gallery ] ) ? $status[ $gallery ] : array();
					$posts = isg_posts_for_gallery( $gallery );
					$error = isset( $row['error'] ) ? (string) $row['error'] : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $gallery ); ?></strong></td>
						<td><?php echo isset( $row['rows'] ) ? esc_html( (string) (int) $row['rows'] ) : '&mdash;'; ?></td>
						<td>
							<?php
							if ( ! empty( $row['last_sync'] ) ) {
								printf(
									/* translators: %s: human-readable time difference */
									esc_html__( '%s ago', 'image-snippets-gallery' ),
									esc_html( human_time_diff( (int) $row['last_sync'] ) )
								);
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<?php
							if ( empty( $posts ) ) {
								esc_html_e( 'No published pages', 'image-snippets-gallery' );
							} else {
								$links = array();
								foreach ( $posts as $post_id ) {
									$links[] = sprintf(
										'<a href="%s">%s</a>',
										esc_url( (string) get_permalink( $post_id ) ),
										esc_html( (string) get_the_title( $post_id ) )
									);
								}
								echo wp_kses_post( implode( ', ', $links ) );
							}
							?>
						</td>
						<td>
							<?php if ( '' !== $error ) : ?>
								<span style="color:#b32d2e;"><?php echo esc_html( $error ); ?></span>
							<?php else : ?>
								<span style="color:#00a32a;"><?php esc_html_e( 'OK', 'image-snippets-gallery' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Caching', 'image-snippets-gallery' ); ?></h2>
		<?php if ( ! isg_page_cache_detected() ) : ?>
			<p><?php esc_html_e( 'No page cache detected. Gallery changes appear as soon as they are refreshed.', 'image-snippets-gallery' ); ?></p>
		<?php elseif ( ! empty( $adapters ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of cache plugin names */
					esc_html__( 'A page cache is active and this plugin can clear it (%s). Gallery changes will reach visitors automatically.', 'image-snippets-gallery' ),
					esc_html( implode( ', ', $adapters ) )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<strong><?php esc_html_e( 'A page cache is active but has not been cleared by this plugin yet.', 'image-snippets-gallery' ); ?></strong>
				<?php esc_html_e( 'If gallery changes do not reach visitors, exclude the pages above from your page cache, or ask your host how to clear it when content changes.', 'image-snippets-gallery' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Add Site Health tests. These surface the same facts to anyone debugging the
 * site who does not know this plugin exists.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function isg_site_health_tests( $tests ) {
	$tests['direct']['isg_galleries'] = array(
		'label' => __( 'ImageSnippets galleries are up to date', 'image-snippets-gallery' ),
		'test'  => 'isg_site_health_check',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'isg_site_health_tests' );

/**
 * Report the oldest sync, any endpoint errors, and the page-cache situation.
 *
 * @return array
 */
function isg_site_health_check() {
	$result = array(
		'label'       => __( 'ImageSnippets galleries are up to date', 'image-snippets-gallery' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Content', 'image-snippets-gallery' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'tools.php?page=isg-galleries' ) ),
			esc_html__( 'Review ImageSnippets galleries', 'image-snippets-gallery' )
		),
		'test'        => 'isg_galleries',
	);

	$galleries = isg_indexed_galleries();
	if ( empty( $galleries ) ) {
		$result['label']       = __( 'No ImageSnippets galleries are published', 'image-snippets-gallery' );
		$result['description'] = '<p>' . esc_html__( 'Nothing to check yet.', 'image-snippets-gallery' ) . '</p>';
		return $result;
	}

	$notes  = array();
	$status = isg_sync_status();

	$errors = array();
	$oldest = null;
	foreach ( $galleries as $gallery ) {
		$row = isset( $status[ $gallery ] ) ? $status[ $gallery ] : array();
		if ( ! empty( $row['error'] ) ) {
			$errors[] = $gallery;
		}
		if ( ! empty( $row['last_sync'] ) && ( null === $oldest || (int) $row['last_sync'] < $oldest ) ) {
			$oldest = (int) $row['last_sync'];
		}
	}

	if ( ! empty( $errors ) ) {
		$result['status'] = 'recommended';
		$result['label']  = __( 'Some ImageSnippets galleries could not be updated', 'image-snippets-gallery' );
		$notes[]          = sprintf(
			/* translators: %s: comma-separated gallery names */
			esc_html__( 'These galleries reported an error on their last update: %s.', 'image-snippets-gallery' ),
			esc_html( implode( ', ', $errors ) )
		);
	}

	if ( $oldest ) {
		$notes[] = sprintf(
			/* translators: %s: human-readable time difference */
			esc_html__( 'The least recently updated gallery was refreshed %s ago.', 'image-snippets-gallery' ),
			esc_html( human_time_diff( $oldest ) )
		);
	}

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$notes[] = esc_html__( 'WP-Cron is disabled on this site. Galleries still update, but the refresh happens during a page view rather than in the background.', 'image-snippets-gallery' );
	}

	if ( isg_page_cache_detected() ) {
		$adapters = isg_known_purge_adapters();
		if ( empty( $adapters ) ) {
			$result['status'] = 'recommended';
			$result['label']  = __( 'A page cache may be holding old ImageSnippets galleries', 'image-snippets-gallery' );
			$notes[]          = esc_html__( 'A page cache is active, but this plugin has not been able to clear it. If gallery updates do not reach visitors, exclude the gallery pages from the cache or ask your host how to clear it when content changes.', 'image-snippets-gallery' );
		} else {
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of cache plugin names */
				esc_html__( 'A page cache is active and is cleared automatically (%s).', 'image-snippets-gallery' ),
				esc_html( implode( ', ', $adapters ) )
			);
		}
	}

	$result['description'] = '<p>' . implode( ' ', $notes ) . '</p>';
	return $result;
}
