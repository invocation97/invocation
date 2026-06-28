<?php
/**
 * Invocation admin page: the Site Brief editor + AI setup guidance.
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
			'invocation_render_admin_page',
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

/**
 * Render the Invocation admin page: an AI-setup callout plus the React app mount.
 */
function invocation_render_admin_page(): void {
	$install_url    = admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( 'AI Provider for' ) );
	$connectors_url = (string) menu_page_url( 'options-connectors-wp-admin', false );
	if ( '' === $connectors_url ) {
		$connectors_url = admin_url( 'options-general.php' );
	}
	?>
	<div class="wrap">
		<div class="notice notice-info inline" style="margin: 16px 0; padding: 12px 16px;">
			<p style="margin: 0 0 8px;">
				<strong><?php esc_html_e( 'Set up AI', 'invocation' ); ?></strong>
				&mdash; <?php esc_html_e( 'Invocation generates content through your own AI provider. Two quick steps:', 'invocation' ); ?>
			</p>
			<p style="margin: 0;">
				<a class="button button-secondary" href="<?php echo esc_url( $install_url ); ?>"><?php esc_html_e( '1. Install an AI provider plugin', 'invocation' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( $connectors_url ); ?>"><?php esc_html_e( '2. Add your API key (Connectors)', 'invocation' ); ?></a>
			</p>
			<p style="margin: 8px 0 0; color: #646970;">
				<?php esc_html_e( 'Official free providers: AI Provider for OpenAI, Anthropic (Claude), or Google (Gemini).', 'invocation' ); ?>
			</p>
		</div>
		<div id="invocation-admin-root"></div>
	</div>
	<?php
}
