<?php
/**
 * Self-hosted updates from GitHub Releases.
 *
 * The plugin is not in the wordpress.org directory, so on its own WordPress has
 * nowhere to ask whether a newer version exists: every site would stay on
 * whatever zip was uploaded by hand. Plugin Update Checker (Yahnis Elsts, MIT)
 * hooks the same update transient wordpress.org feeds, pointed at the GitHub
 * repository instead, so installed sites get the native "update available"
 * notice and one-click update.
 *
 * The `Update URI` header in the main file tells WordPress (5.8+) that this
 * plugin is not served by wordpress.org, so a directory plugin that happened to
 * share our slug could never be offered as an "update" to it.
 *
 * @package ImageSnippetsGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api as PucVcsApi;

define( 'ISG_UPDATE_REPO', 'https://github.com/gchartier/image-snippets-gallery/' );

/**
 * Wire the update checker to GitHub Releases.
 *
 * Only a published release's attached zip is ever offered. GitHub's automatic
 * source archives are never acceptable: `build/` is gitignored, so a source
 * tarball installs a plugin whose block cannot register. Hence both
 * REQUIRE_RELEASE_ASSETS and the strategy filter below, which removes the
 * library's fallbacks to "highest tag" and "branch head". A release cut without
 * its zip therefore results in no update being offered, never a broken one.
 *
 * @return void
 */
function isg_init_update_checker() {
	$checker = PucFactory::buildUpdateChecker(
		ISG_UPDATE_REPO,
		ISG_PLUGIN_FILE,
		'image-snippets-gallery'
	);
	$checker->getVcsApi()->enableReleaseAssets(
		'/^image-snippets-gallery-.*\.zip$/',
		PucVcsApi::REQUIRE_RELEASE_ASSETS
	);
}
isg_init_update_checker();

/**
 * Restrict update detection to GitHub Releases; never tags or branches.
 *
 * @param array<string,callable> $strategies Detection strategies, in order.
 * @return array<string,callable>
 */
function isg_update_detection_strategies( $strategies ) {
	return array_intersect_key( $strategies, array( PucVcsApi::STRATEGY_LATEST_RELEASE => true ) );
}
add_filter( 'puc_vcs_update_detection_strategies-image-snippets-gallery', 'isg_update_detection_strategies' );
