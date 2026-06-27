<?php
/**
 * Invocation admin page (Settings → the Site Brief editor + onboarding).
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INVOCATION_ADMIN_SLUG = 'invocation';

/**
 * Register the top-level Invocation admin menu.
 */
add_action(
	'admin_menu',
	static function (): void {
		add_menu_page(
			__( 'Invocation', 'invocation' ),
			__( 'Invocation', 'invocation' ),
			'manage_options',
			INVOCATION_ADMIN_SLUG,
			static function (): void {
				echo '<div class="wrap"><div id="invocation-admin-root"></div></div>';
			},
			'dashicons-layout',
			59
		);
	}
);

/**
 * Enqueue the admin React app on the Invocation page.
 */
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( 'toplevel_page_' . INVOCATION_ADMIN_SLUG !== $hook ) {
			return;
		}

		$asset_path = INVOCATION_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			'invocation-admin',
			INVOCATION_URL . 'build/admin.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? INVOCATION_VERSION,
			true
		);
		wp_set_script_translations( 'invocation-admin', 'invocation' );
		wp_enqueue_style( 'wp-components' );
	}
);

