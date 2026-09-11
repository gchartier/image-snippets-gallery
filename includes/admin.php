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
function isgal_admin_menu() {
	add_management_page(
		__( 'ImageSnippets Galleries', 'image-snippets-gallery' ),
		__( 'ImageSnippets', 'image-snippets-gallery' ),
		'edit_posts',
		'isgal-galleries',
		'isgal_render_admin_page'
	);
}
add_action( 'admin_menu', 'isgal_admin_menu' );

/**
 * Add a "Galleries" link to this plugin's row on the Plugins screen, first in
 * the list, so the Tools page can be found from the place people look first.
 *
 * Gated on the same capability as the page itself.
 *
 * @param string[] $links Existing action links (Deactivate, etc.).
 * @return string[]
 */
function isgal_plugin_action_links( $links ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return $links;
	}
	$galleries = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'tools.php?page=isgal-galleries' ) ),
		esc_html__( 'Galleries', 'image-snippets-gallery' )
	);
	// Keyed, not array_unshift(): WordPress uses the key as the <span> class.
	return array_merge( array( 'galleries' => $galleries ), $links );
}
add_filter( 'plugin_action_links_' . ISGAL_PLUGIN_BASENAME, 'isgal_plugin_action_links' );

/**
 * Handle the screen's two actions before anything renders.
 *
 * @return void
 */
function isgal_handle_admin_actions() {
	if ( ! isset( $_POST['isgal_action'] ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['isgal_action'] ) );
	check_admin_referer( 'isgal_admin_' . $action );

	if ( 'refresh_all' === $action ) {
		$results = isgal_refresh_all_galleries();
		$failed  = 0;
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				++$failed;
			}
		}
		add_settings_error(
			'isgal',
			'isgal_refreshed',
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

	if ( 'dismiss_welcome' === $action ) {
		delete_option( 'isgal_welcome' );
	}

	if ( 'show_welcome' === $action ) {
		update_option( 'isgal_welcome', 1, false );
	}

	if ( 'create_demo' === $action ) {
		$gallery = isset( $_POST['isgal_gallery'] ) ? sanitize_text_field( wp_unslash( $_POST['isgal_gallery'] ) ) : '';
		$pattern = isset( $_POST['isgal_pattern'] ) ? sanitize_key( wp_unslash( $_POST['isgal_pattern'] ) ) : 'gallery-with-title';
		$post_id = isgal_create_demo_page( $gallery, $pattern );
		if ( is_wp_error( $post_id ) ) {
			add_settings_error( 'isgal', 'isgal_demo', $post_id->get_error_message(), 'error' );
		} else {
			delete_option( 'isgal_welcome' );
			wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
			exit;
		}
	}

	if ( 'sync_one' === $action ) {
		$gallery  = isset( $_POST['isgal_gallery'] ) ? sanitize_text_field( wp_unslash( $_POST['isgal_gallery'] ) ) : '';
		$endpoint = isset( $_POST['isgal_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['isgal_endpoint'] ) ) : isgal_default_endpoint();
		$result   = isgal_refresh_gallery( $endpoint ? $endpoint : isgal_default_endpoint(), $gallery );
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'isgal', 'isgal_synced', sprintf( '%s: %s', $gallery, $result->get_error_message() ), 'error' );
		} else {
			add_settings_error(
				'isgal',
				'isgal_synced',
				sprintf(
					/* translators: 1: gallery name, 2: images, 3: added, 4: updated, 5: removed */
					__( 'Synced %1$s: %2$d images (%3$d added, %4$d updated, %5$d removed).', 'image-snippets-gallery' ),
					$gallery,
					$result['images'],
					$result['added'],
					$result['updated'],
					$result['removed']
				),
				'success'
			);
		}
	}

	if ( 'reset_mirror' === $action ) {
		$count = isgal_mirror_drop_all();
		add_settings_error(
			'isgal',
			'isgal_reset',
			sprintf(
				/* translators: %d: number of images */
				__( 'Cleared the stored copy of every gallery (%d images). Each gallery is fetched again the next time its page is viewed.', 'image-snippets-gallery' ),
				$count
			),
			'success'
		);
	}

	if ( 'rebuild_index' === $action ) {
		$count = isgal_rebuild_index();
		add_settings_error(
			'isgal',
			'isgal_reindexed',
			sprintf(
				/* translators: %d: number of posts */
				__( 'Rebuilt the index. %d posts contain a gallery.', 'image-snippets-gallery' ),
				$count
			),
			'success'
		);
	}

	if ( 'save_defaults' === $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$endpoint = isset( $_POST['isgal_default_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['isgal_default_endpoint'] ) ) : '';
		$ttl      = isset( $_POST['isgal_default_ttl'] ) ? absint( wp_unslash( $_POST['isgal_default_ttl'] ) ) : 10;
		$endpoint = trim( $endpoint );
		// The built-in endpoint is represented by an empty option, so clearing
		// the field returns to it and a future change to the constant applies.
		if ( ISGAL_DEFAULT_ENDPOINT === $endpoint ) {
			$endpoint = '';
		}
		update_option( 'isgal_default_endpoint', $endpoint, false );
		update_option( 'isgal_default_ttl', max( 0, min( 1440, $ttl ) ), false );
		add_settings_error( 'isgal', 'isgal_defaults', __( 'Defaults saved. Galleries without their own override use them from now on.', 'image-snippets-gallery' ), 'success' );
	}

	if ( 'reset_overrides' === $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$count = isgal_reset_block_overrides();
		isgal_rebuild_index();
		add_settings_error(
			'isgal',
			'isgal_overrides_reset',
			sprintf(
				/* translators: %d: number of gallery blocks changed */
				__( 'Removed the endpoint and refetch overrides from %d gallery blocks. They now follow the site defaults.', 'image-snippets-gallery' ),
				$count
			),
			'success'
		);
	}
}

/**
 * Strip the per-block `endpoint` and `cacheTtl` overrides from every
 * ImageSnippets Gallery block on the site so they follow the site defaults.
 *
 * Only the block's own comment delimiter is rewritten, with WordPress's own
 * attribute serialiser, so no other content in the post is touched. Posts are
 * saved through wp_update_post(), which keeps a revision.
 *
 * @return int Number of blocks changed.
 */
function isgal_reset_block_overrides() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin action; a LIKE over post_content has no WP_Query equivalent.
	$post_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_content LIKE '%<!-- wp:imagesnippets/gallery%'
		    AND post_status NOT IN ( 'auto-draft', 'trash', 'inherit' )
		  LIMIT 1000"
	);

	$changed = 0;
	foreach ( (array) $post_ids as $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$touched = 0;
		$content = preg_replace_callback(
			'#<!--\s+wp:imagesnippets/gallery(\s+(\{.*?\}))?\s+(/?)-->#s',
			static function ( $m ) use ( &$touched ) {
				$attrs = ! empty( $m[2] ) ? json_decode( $m[2], true ) : array();
				if ( ! is_array( $attrs ) || ( ! array_key_exists( 'endpoint', $attrs ) && ! array_key_exists( 'cacheTtl', $attrs ) ) ) {
					return $m[0];
				}
				unset( $attrs['endpoint'], $attrs['cacheTtl'] );
				++$touched;
				$json = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
				return '<!-- wp:imagesnippets/gallery' . $json . ' ' . $m[3] . '-->';
			},
			$post->post_content
		);
		if ( $touched && is_string( $content ) && $content !== $post->post_content ) {
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			);
			$changed += $touched;
		}
	}
	return $changed;
}

/**
 * Hand the site defaults to the block editor so the block's Advanced section
 * can show what "leave blank" means.
 *
 * An inline script rather than block_editor_settings_all: the editor package
 * forwards only an allow-list of settings to the block editor store, so a
 * custom key never reaches getSettings() in the post editor.
 *
 * @return void
 */
function isgal_editor_defaults_script() {
	wp_add_inline_script(
		generate_block_asset_handle( 'imagesnippets/gallery', 'editorScript' ),
		'window.isgalEditorDefaults = ' . wp_json_encode(
			array(
				'endpoint'   => isgal_default_endpoint(),
				'ttl'        => isgal_default_ttl_minutes(),
				'reorderUrl' => admin_url( 'tools.php?page=isgal-galleries' ),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'isgal_editor_defaults_script' );
add_action( 'load-tools_page_isgal-galleries', 'isgal_handle_admin_actions' );

/**
 * A submit button wrapped in its own form and nonce.
 *
 * @param string $action Action key.
 * @param string $label  Button label.
 * @param string $css_class Button class.
 * @param array  $fields Extra hidden fields.
 * @return void
 */
function isgal_action_button( $action, $label, $css_class = 'button', array $fields = array() ) {
	?>
	<form method="post" style="display:inline-block;margin-right:.5em;">
		<?php wp_nonce_field( 'isgal_admin_' . $action ); ?>
		<input type="hidden" name="isgal_action" value="<?php echo esc_attr( $action ); ?>">
		<?php foreach ( $fields as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endforeach; ?>
		<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

/**
 * The endpoint the site's blocks use for a gallery. Almost always the default;
 * a block with an override wins if one exists.
 *
 * @param string $gallery Gallery name.
 * @return string
 */
function isgal_gallery_endpoint_in_use( $gallery ) {
	static $blocks = null;
	if ( null === $blocks ) {
		$blocks = isgal_indexed_gallery_blocks();
	}
	foreach ( $blocks as $block ) {
		if ( $block['gallery'] === $gallery ) {
			return isgal_resolve_endpoint( isgal_resolve_attributes( $block['attrs'] ) );
		}
	}
	return isgal_default_endpoint();
}

/**
 * Render the Tools screen.
 *
 * @return void
 */
function isgal_render_admin_page() {
	if ( isset( $_GET['isgal_reorder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selection; saves go through the REST route with its own nonce.
		isgal_render_reorder_page(
			sanitize_text_field( wp_unslash( $_GET['isgal_reorder'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			isset( $_GET['isgal_endpoint'] ) ? esc_url_raw( wp_unslash( $_GET['isgal_endpoint'] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		return;
	}

	$galleries = isgal_indexed_galleries();
	$status    = isgal_sync_status();
	$adapters  = isgal_known_purge_adapters();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ImageSnippets Galleries', 'image-snippets-gallery' ); ?></h1>

		<?php settings_errors( 'isgal' ); ?>

		<?php isgal_render_welcome_panel( $galleries ); ?>

		<p>
			<?php esc_html_e( 'Each gallery is fetched from ImageSnippets on a schedule and stored on this site, so pages render without waiting on the network and WordPress search can find the images. Refresh pulls the latest from ImageSnippets now.', 'image-snippets-gallery' ); ?>
		</p>
		<p>
			<?php isgal_action_button( 'refresh_all', __( 'Refresh all galleries', 'image-snippets-gallery' ), 'button button-primary' ); ?>
			<?php isgal_action_button( 'rebuild_index', __( 'Rebuild index', 'image-snippets-gallery' ) ); ?>
			<?php isgal_action_button( 'reset_mirror', __( 'Clear stored copies', 'image-snippets-gallery' ) ); ?>
			<?php if ( ! get_option( 'isgal_welcome' ) ) : ?>
				<?php isgal_action_button( 'show_welcome', __( 'Show the getting-started panel', 'image-snippets-gallery' ), 'button-link' ); ?>
			<?php endif; ?>
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
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $galleries as $gallery ) : ?>
					<?php
					$row      = isset( $status[ $gallery ] ) ? $status[ $gallery ] : array();
					$posts    = isgal_posts_for_gallery( $gallery );
					$error    = isset( $row['error'] ) ? (string) $row['error'] : '';
					$endpoint = isgal_gallery_endpoint_in_use( $gallery );
					$term     = isgal_gallery_term( $endpoint, $gallery );
					$synced   = isgal_gallery_synced_at( $term );
					?>
					<tr>
						<td><strong><?php echo esc_html( $gallery ); ?></strong></td>
						<td><?php echo $term instanceof WP_Term ? esc_html( (string) (int) $term->count ) : '&mdash;'; ?></td>
						<td>
							<?php
							if ( $synced ) {
								printf(
									/* translators: %s: human-readable time difference */
									esc_html__( '%s ago', 'image-snippets-gallery' ),
									esc_html( human_time_diff( $synced ) )
								);
							} else {
								esc_html_e( 'Not yet', 'image-snippets-gallery' );
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
						<td>
							<?php
							isgal_action_button(
								'sync_one',
								__( 'Refresh', 'image-snippets-gallery' ),
								'button button-small',
								array(
									'isgal_gallery'  => $gallery,
									'isgal_endpoint' => $endpoint,
								)
							);
							?>
							<a class="button button-small" href="<?php echo esc_url( isgal_reorder_url( $gallery, $endpoint ) ); ?>">
								<?php
								if ( ! empty( isgal_gallery_manual_order( $term ) ) ) {
									esc_html_e( 'Edit order', 'image-snippets-gallery' );
								} else {
									esc_html_e( 'Arrange', 'image-snippets-gallery' );
								}
								?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<h2><?php esc_html_e( 'Defaults', 'image-snippets-gallery' ); ?></h2>
		<p><?php esc_html_e( 'Every gallery block uses these unless it sets its own values under Advanced in the block settings.', 'image-snippets-gallery' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'isgal_admin_save_defaults' ); ?>
			<input type="hidden" name="isgal_action" value="save_defaults">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="isgal_default_endpoint"><?php esc_html_e( 'Default SPARQL endpoint', 'image-snippets-gallery' ); ?></label></th>
					<td>
						<input type="url" class="regular-text code" id="isgal_default_endpoint" name="isgal_default_endpoint"
							value="<?php echo esc_attr( (string) get_option( 'isgal_default_endpoint', '' ) ); ?>"
							placeholder="<?php echo esc_attr( ISGAL_DEFAULT_ENDPOINT ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank for the ImageSnippets endpoint.', 'image-snippets-gallery' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isgal_default_ttl"><?php esc_html_e( 'Default refetch rate', 'image-snippets-gallery' ); ?></label></th>
					<td>
						<input type="number" class="small-text" id="isgal_default_ttl" name="isgal_default_ttl" min="0" max="1440" step="1"
							value="<?php echo esc_attr( (string) isgal_default_ttl_minutes() ); ?>">
						<?php esc_html_e( 'minutes', 'image-snippets-gallery' ); ?>
						<p class="description"><?php esc_html_e( 'How often each gallery is checked against ImageSnippets and the stored copy updated. 0 checks on every page view.', 'image-snippets-gallery' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save defaults', 'image-snippets-gallery' ); ?></button>
			</p>
		</form>
		<p>
			<?php isgal_action_button( 'reset_overrides', __( 'Reset all galleries to defaults', 'image-snippets-gallery' ) ); ?>
			<span class="description"><?php esc_html_e( 'Removes any per-gallery endpoint or refetch override so every block follows the defaults above.', 'image-snippets-gallery' ); ?></span>
		</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Caching', 'image-snippets-gallery' ); ?></h2>
		<?php if ( ! isgal_page_cache_detected() ) : ?>
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
 * Getting-started panel at the top of Tools → ImageSnippets. Shown after
 * activation until dismissed or a page is created from it, and whenever the
 * site has no gallery yet. Offers the site's galleries (from ImageSnippets,
 * cached) and a button that creates a draft page holding one and opens it.
 *
 * @param array $galleries Indexed gallery names (may be empty).
 * @return void
 */
function isgal_render_welcome_panel( array $galleries ) {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	if ( ! empty( $galleries ) && ! get_option( 'isgal_welcome' ) ) {
		return;
	}
	$list = isgal_list_galleries( isgal_default_endpoint() );
	if ( is_wp_error( $list ) ) {
		$list = array();
	}
	$patterns = function_exists( 'isgal_pattern_definitions' ) ? isgal_pattern_definitions() : array();
	?>
	<div class="card" style="max-width:none">
		<h2><?php esc_html_e( 'Get started', 'image-snippets-gallery' ); ?></h2>
		<p>
			<?php esc_html_e( 'A gallery is a block. Add "ImageSnippets Gallery" to any page and pick one of your galleries in its Source panel — or let this create a draft page for you.', 'image-snippets-gallery' ); ?>
		</p>
		<?php if ( empty( $list ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: endpoint URL */
					esc_html__( 'The list of galleries could not be fetched from %s just now. You can still type a gallery name in the block.', 'image-snippets-gallery' ),
					'<code>' . esc_html( isgal_default_endpoint() ) . '</code>'
				);
				?>
			</p>
		<?php else : ?>
			<form method="post" style="display:flex;flex-wrap:wrap;gap:.5em 1em;align-items:end">
				<?php wp_nonce_field( 'isgal_admin_create_demo' ); ?>
				<input type="hidden" name="isgal_action" value="create_demo">
				<label>
					<span style="display:block"><?php esc_html_e( 'Gallery', 'image-snippets-gallery' ); ?></span>
					<select name="isgal_gallery">
						<?php foreach ( $list as $item ) : ?>
							<option value="<?php echo esc_attr( $item['value'] ); ?>">
								<?php
								printf(
									/* translators: 1: gallery name, 2: image count */
									esc_html__( '%1$s — %2$d images', 'image-snippets-gallery' ),
									esc_html( $item['value'] ),
									(int) $item['count']
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( $patterns ) : ?>
				<label>
					<span style="display:block"><?php esc_html_e( 'Look', 'image-snippets-gallery' ); ?></span>
					<select name="isgal_pattern">
						<?php foreach ( $patterns as $slug => $pattern ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $pattern['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php endif; ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create a draft page with it', 'image-snippets-gallery' ); ?></button>
			</form>
		<?php endif; ?>
		<p class="description" style="margin-top:.75em">
			<?php esc_html_e( 'By default a gallery shows three columns of 4:3 crops, captions off, links to the image on ImageSnippets, and each image\'s full provenance as JSON-LD. Every setting is in the block\'s sidebar; the Patterns tab of the inserter has ready-made looks under "ImageSnippets".', 'image-snippets-gallery' ); ?>
		</p>
		<?php if ( get_option( 'isgal_welcome' ) ) : ?>
			<?php isgal_action_button( 'dismiss_welcome', __( 'Hide this', 'image-snippets-gallery' ), 'button-link' ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * One notice on the Plugins screen after activation, pointing at the panel.
 *
 * @return void
 */
function isgal_welcome_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id || ! get_option( 'isgal_welcome' ) || ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'ImageSnippets Gallery is ready.', 'image-snippets-gallery' ),
		esc_url( admin_url( 'tools.php?page=isgal-galleries' ) ),
		esc_html__( 'Pick a gallery and create a page →', 'image-snippets-gallery' )
	);
}
add_action( 'admin_notices', 'isgal_welcome_notice' );

/**
 * Link to the reorder screen for a gallery.
 *
 * @param string $gallery  Gallery name.
 * @param string $endpoint SPARQL endpoint URL.
 * @return string
 */
function isgal_reorder_url( $gallery, $endpoint = '' ) {
	$args = array(
		'page'          => 'isgal-galleries',
		'isgal_reorder' => $gallery,
	);
	if ( '' !== $endpoint && isgal_default_endpoint() !== $endpoint ) {
		$args['isgal_endpoint'] = $endpoint;
	}
	return add_query_arg( $args, admin_url( 'tools.php' ) );
}

/**
 * The reorder screen: every image in the gallery as a draggable tile. Reads and
 * saves through the REST order route, so the same logic serves anything else
 * that wants to arrange a gallery later.
 *
 * @param string $gallery  Gallery name.
 * @param string $endpoint SPARQL endpoint URL, or '' for the default.
 * @return void
 */
function isgal_render_reorder_page( $gallery, $endpoint = '' ) {
	$gallery = trim( (string) $gallery );
	if ( '' === $endpoint ) {
		$endpoint = isgal_gallery_endpoint_in_use( $gallery );
	}
	?>
	<div class="wrap isgal-reorder">
		<h1>
			<?php
			printf(
				/* translators: %s: gallery name */
				esc_html__( 'Arrange: %s', 'image-snippets-gallery' ),
				esc_html( $gallery )
			);
			?>
		</h1>
		<p>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=isgal-galleries' ) ); ?>">&larr; <?php esc_html_e( 'All galleries', 'image-snippets-gallery' ); ?></a>
		</p>
		<p>
			<?php esc_html_e( 'Drag images into the order you want. Blocks showing this gallery use it when their Sort by is set to Manual. Images added on ImageSnippets later appear after the ones you arranged until you place them.', 'image-snippets-gallery' ); ?>
		</p>
		<p class="isgal-reorder__actions">
			<button type="button" class="button button-primary" id="isgal-reorder-save" disabled><?php esc_html_e( 'Save order', 'image-snippets-gallery' ); ?></button>
			<button type="button" class="button" id="isgal-reorder-clear"><?php esc_html_e( 'Clear arrangement', 'image-snippets-gallery' ); ?></button>
			<span class="isgal-reorder__status" id="isgal-reorder-status" role="status" aria-live="polite"></span>
		</p>
		<ol class="isgal-reorder__grid" id="isgal-reorder-grid" aria-label="<?php esc_attr_e( 'Images, first to last', 'image-snippets-gallery' ); ?>">
			<li class="isgal-reorder__loading"><?php esc_html_e( 'Loading images…', 'image-snippets-gallery' ); ?></li>
		</ol>
	</div>
	<?php
	wp_add_inline_script(
		'isgal-reorder',
		'window.isgalReorder = ' . wp_json_encode(
			array(
				'gallery'  => $gallery,
				'endpoint' => $endpoint,
				'route'    => rest_url( 'imagesnippets/v1/order' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'saving'     => __( 'Saving…', 'image-snippets-gallery' ),
					'saved'      => __( 'Saved. Pages showing this gallery have been refreshed.', 'image-snippets-gallery' ),
					'cleared'    => __( 'Arrangement cleared; blocks fall back to their date or title order.', 'image-snippets-gallery' ),
					/* translators: %s: error message */
					'failed'     => __( 'Could not save: %s', 'image-snippets-gallery' ),
					/* translators: %s: error message */
					'loadFailed' => __( 'Could not load the gallery: %s', 'image-snippets-gallery' ),
					'empty'      => __( 'This gallery has no images yet.', 'image-snippets-gallery' ),
					'unsaved'    => __( 'You have unsaved changes to the order.', 'image-snippets-gallery' ),
					'moveUp'     => __( 'Move earlier', 'image-snippets-gallery' ),
					'moveDown'   => __( 'Move later', 'image-snippets-gallery' ),
					'newBadge'   => __( 'New', 'image-snippets-gallery' ),
					'confirmClr' => __( 'Clear the arrangement for this gallery?', 'image-snippets-gallery' ),
				),
			)
		) . ';',
		'before'
	);
}

/**
 * Load the reorder screen's script and styles only on that screen.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function isgal_reorder_assets( $hook ) {
	if ( 'tools_page_isgal-galleries' !== $hook || ! isset( $_GET['isgal_reorder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	wp_enqueue_script( 'isgal-reorder', plugins_url( 'assets/reorder.js', ISGAL_PLUGIN_FILE ), array(), ISGAL_VERSION, true );
	wp_enqueue_style( 'isgal-reorder', plugins_url( 'assets/reorder.css', ISGAL_PLUGIN_FILE ), array(), ISGAL_VERSION );
}
add_action( 'admin_enqueue_scripts', 'isgal_reorder_assets' );

/**
 * How old a gallery's last sync may be before Site Health calls it stale.
 *
 * Three times the longest interval any block asks for, and never less than an
 * hour: hosts run cron coarsely, and a warning that fires on ordinary latency
 * teaches people to ignore it.
 *
 * @param string $gallery Gallery name.
 * @return int Seconds.
 */
function isgal_stale_after( $gallery ) {
	$max = 0;
	foreach ( isgal_indexed_gallery_blocks() as $block ) {
		if ( $block['gallery'] === $gallery ) {
			$max = max( $max, isgal_configured_ttl( isgal_resolve_attributes( $block['attrs'] ) ) );
		}
	}
	return max( HOUR_IN_SECONDS, 3 * $max );
}

/**
 * Add Site Health tests. These surface the same facts to anyone debugging the
 * site who does not know this plugin exists.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function isgal_site_health_tests( $tests ) {
	$tests['direct']['isgal_galleries'] = array(
		'label' => __( 'ImageSnippets galleries are up to date', 'image-snippets-gallery' ),
		'test'  => 'isgal_site_health_check',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'isgal_site_health_tests' );

/**
 * Report the oldest sync, any endpoint errors, and the page-cache situation.
 *
 * @return array
 */
function isgal_site_health_check() {
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
			esc_url( admin_url( 'tools.php?page=isgal-galleries' ) ),
			esc_html__( 'Review ImageSnippets galleries', 'image-snippets-gallery' )
		),
		'test'        => 'isgal_galleries',
	);

	$galleries = isgal_indexed_galleries();
	if ( empty( $galleries ) ) {
		$result['label']       = __( 'No ImageSnippets galleries are published', 'image-snippets-gallery' );
		$result['description'] = '<p>' . esc_html__( 'Nothing to check yet.', 'image-snippets-gallery' ) . '</p>';
		return $result;
	}

	$notes  = array();
	$status = isgal_sync_status();

	$errors = array();
	$stale  = array();
	$never  = array();
	$oldest = null;
	foreach ( $galleries as $gallery ) {
		$row = isset( $status[ $gallery ] ) ? $status[ $gallery ] : array();
		if ( ! empty( $row['error'] ) ) {
			$errors[] = $gallery;
		}
		$synced = isgal_gallery_synced_at( isgal_gallery_term( isgal_gallery_endpoint_in_use( $gallery ), $gallery ) );
		if ( ! $synced ) {
			if ( ! empty( isgal_posts_for_gallery( $gallery ) ) ) {
				$never[] = $gallery;
			}
			continue;
		}
		if ( null === $oldest || $synced < $oldest ) {
			$oldest = $synced;
		}
		if ( ( time() - $synced ) > isgal_stale_after( $gallery ) ) {
			$stale[] = $gallery;
		}
	}

	if ( ! empty( $stale ) || ! empty( $never ) ) {
		$result['status'] = 'recommended';
		$result['label']  = __( 'Some ImageSnippets galleries have not updated recently', 'image-snippets-gallery' );
		if ( ! empty( $never ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated gallery names */
				esc_html__( 'These galleries are on published pages but have never been fetched: %s.', 'image-snippets-gallery' ),
				esc_html( implode( ', ', $never ) )
			);
		}
		if ( ! empty( $stale ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated gallery names */
				esc_html__( 'These galleries are well past their update interval, which usually means scheduled tasks are not running on this site: %s.', 'image-snippets-gallery' ),
				esc_html( implode( ', ', $stale ) )
			);
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

	$mirrored = isgal_mirrored_image_count();
	if ( $mirrored > 0 ) {
		$notes[] = sprintf(
			/* translators: 1: number of images, 2: number of galleries */
			esc_html( _n( '%1$s image across %2$s galleries is kept on this site, so gallery pages are built from that copy and make no request to ImageSnippets while a visitor waits.', '%1$s images across %2$s galleries are kept on this site, so gallery pages are built from that copy and make no request to ImageSnippets while a visitor waits.', $mirrored, 'image-snippets-gallery' ) ),
			esc_html( number_format_i18n( $mirrored ) ),
			esc_html( number_format_i18n( count( $galleries ) ) )
		);
	}

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$notes[] = esc_html__( 'WP-Cron is disabled on this site. Galleries still update, but the refresh happens during a page view rather than in the background.', 'image-snippets-gallery' );
	}

	if ( isgal_page_cache_detected() ) {
		$adapters = isgal_known_purge_adapters();
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

/**
 * How many images the mirror holds, across every gallery.
 *
 * @return int
 */
function isgal_mirrored_image_count() {
	$counts = wp_count_posts( ISGAL_POST_TYPE );
	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * A section under Site Health → Info, so the numbers behind the performance
 * claim are somewhere a person can read them: what is mirrored, how fresh it
 * is, and whether the page cache is being cleared.
 *
 * @param array $info Debug sections.
 * @return array
 */
function isgal_site_health_info( $info ) {
	$galleries = isgal_indexed_galleries();
	$status    = isgal_sync_status();
	$oldest    = null;
	$errors    = 0;
	foreach ( $galleries as $gallery ) {
		$synced = isgal_gallery_synced_at( isgal_gallery_term( isgal_gallery_endpoint_in_use( $gallery ), $gallery ) );
		if ( $synced && ( null === $oldest || $synced < $oldest ) ) {
			$oldest = $synced;
		}
		if ( ! empty( $status[ $gallery ]['error'] ) ) {
			++$errors;
		}
	}
	$adapters = isgal_page_cache_detected() ? isgal_known_purge_adapters() : array();

	$info['image-snippets-gallery'] = array(
		'label'  => __( 'ImageSnippets Gallery', 'image-snippets-gallery' ),
		'fields' => array(
			'version'     => array(
				'label' => __( 'Version', 'image-snippets-gallery' ),
				'value' => ISGAL_VERSION,
			),
			'galleries'   => array(
				'label' => __( 'Galleries on published pages', 'image-snippets-gallery' ),
				'value' => number_format_i18n( count( $galleries ) ),
			),
			'mirrored'    => array(
				'label' => __( 'Images kept on this site', 'image-snippets-gallery' ),
				'value' => number_format_i18n( isgal_mirrored_image_count() ),
			),
			'requests'    => array(
				'label' => __( 'Requests to ImageSnippets per page view', 'image-snippets-gallery' ),
				'value' => __( '0 — pages render from the local copy; refreshes run in the background', 'image-snippets-gallery' ),
			),
			'oldest_sync' => array(
				'label' => __( 'Least recently refreshed gallery', 'image-snippets-gallery' ),
				'value' => $oldest ? sprintf( /* translators: %s: human-readable time difference */ __( '%s ago', 'image-snippets-gallery' ), human_time_diff( $oldest ) ) : __( 'Never', 'image-snippets-gallery' ),
			),
			'errors'      => array(
				'label' => __( 'Galleries whose last refresh failed', 'image-snippets-gallery' ),
				'value' => number_format_i18n( $errors ),
			),
			'page_cache'  => array(
				'label' => __( 'Page cache', 'image-snippets-gallery' ),
				'value' => isgal_page_cache_detected()
					? ( $adapters ? sprintf( /* translators: %s: comma-separated cache plugin names */ __( 'Detected, cleared automatically (%s)', 'image-snippets-gallery' ), implode( ', ', $adapters ) ) : __( 'Detected, not cleared by this plugin', 'image-snippets-gallery' ) )
					: __( 'Not detected', 'image-snippets-gallery' ),
			),
			'endpoint'    => array(
				'label'   => __( 'Default endpoint', 'image-snippets-gallery' ),
				'value'   => isgal_default_endpoint(),
				'private' => false,
			),
		),
	);
	return $info;
}
add_filter( 'debug_information', 'isgal_site_health_info' );
