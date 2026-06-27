<?php
/**
 * Blocksmith admin page (Settings → the Site Brief editor + onboarding).
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BLOCKSMITH_ADMIN_SLUG = 'blocksmith';

/**
 * Register the top-level Blocksmith admin menu.
 */
add_action(
	'admin_menu',
	static function (): void {
		add_menu_page(
			__( 'Blocksmith', 'blocksmith' ),
			__( 'Blocksmith', 'blocksmith' ),
			'manage_options',
			BLOCKSMITH_ADMIN_SLUG,
			static function (): void {
				echo '<div class="wrap"><div id="blocksmith-admin-root"></div></div>';
			},
			'dashicons-layout',
			59
		);
	}
);

/**
 * Enqueue the admin React app on the Blocksmith page.
 */
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( 'toplevel_page_' . BLOCKSMITH_ADMIN_SLUG !== $hook ) {
			return;
		}

		$asset_path = BLOCKSMITH_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			'blocksmith-admin',
			BLOCKSMITH_URL . 'build/admin.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? BLOCKSMITH_VERSION,
			true
		);
		wp_set_script_translations( 'blocksmith-admin', 'blocksmith' );
		wp_enqueue_style( 'wp-components' );
	}
);

/**
 * Onboarding nudge: until a Site Brief exists, point admins to the setup page.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_' . BLOCKSMITH_ADMIN_SLUG === $screen->id ) {
			return; // Don't nag on the page itself.
		}
		if ( function_exists( 'blocksmith_has_site_brief' ) && blocksmith_has_site_brief() ) {
			return;
		}

		$url     = admin_url( 'admin.php?page=' . BLOCKSMITH_ADMIN_SLUG );
		$message = sprintf(
			/* translators: %s: settings page URL. */
			__( 'Finish setting up Blocksmith: <a href="%s">generate your Site Brief</a> so AI generations stay on-brand.', 'blocksmith' ),
			esc_url( $url )
		);
		wp_admin_notice(
			$message,
			array(
				'type'           => 'info',
				'paragraph_wrap' => true,
				'additional_classes' => array( 'is-dismissible' ),
			)
		);
	}
);
