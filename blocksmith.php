<?php
/**
 * Plugin Name:       Blocksmith
 * Plugin URI:        https://blocksmith.site
 * Description:       Build styled Gutenberg page content with AI, using your own block theme. Brings your own AI provider via WordPress Connectors.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Blocksmith
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blocksmith
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLOCKSMITH_VERSION', '0.1.0' );
define( 'BLOCKSMITH_FILE', __FILE__ );
define( 'BLOCKSMITH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOCKSMITH_URL', plugin_dir_url( __FILE__ ) );

/**
 * Verify the runtime dependencies Blocksmith relies on from WordPress 7.0 core:
 * the Abilities API and the PHP AI Client. If anything is missing we bail early
 * and surface an actionable admin notice instead of fataling.
 *
 * @return bool True when all dependencies are present.
 */
function blocksmith_check_dependencies(): bool {
	$missing = array();

	if ( ! class_exists( 'WP_Ability' ) ) {
		$missing[] = __( 'the Abilities API', 'blocksmith' );
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		$missing[] = __( 'the AI Client', 'blocksmith' );
	}

	if ( empty( $missing ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $missing ): void {
			$message = sprintf(
				/* translators: %s: comma-separated list of missing WordPress features. */
				esc_html__( 'Blocksmith requires WordPress 7.0 or later. Missing: %s.', 'blocksmith' ),
				esc_html( implode( ', ', $missing ) )
			);
			wp_admin_notice( $message, array( 'type' => 'error' ) );
		}
	);

	return false;
}

/**
 * Boot the plugin once we know the platform can support it.
 */
function blocksmith_bootstrap(): void {
	if ( ! blocksmith_check_dependencies() ) {
		return;
	}

	require_once BLOCKSMITH_DIR . 'inc/abilities.php';
	require_once BLOCKSMITH_DIR . 'inc/search-media.php';
	require_once BLOCKSMITH_DIR . 'inc/internal-links.php';
	require_once BLOCKSMITH_DIR . 'inc/patterns.php';
	require_once BLOCKSMITH_DIR . 'inc/context.php';
	require_once BLOCKSMITH_DIR . 'inc/generate-layout.php';
	require_once BLOCKSMITH_DIR . 'inc/refine-block.php';
	require_once BLOCKSMITH_DIR . 'inc/site-brief.php';
	require_once BLOCKSMITH_DIR . 'inc/admin.php';
	require_once BLOCKSMITH_DIR . 'inc/editor.php';
}
add_action( 'plugins_loaded', 'blocksmith_bootstrap' );
