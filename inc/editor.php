<?php
/**
 * Block editor integration: enqueue the Blocksmith sidebar.
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$asset_path = BLOCKSMITH_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			// Build output is missing; run `npm install && npm run build`.
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			'blocksmith-editor',
			BLOCKSMITH_URL . 'build/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? BLOCKSMITH_VERSION,
			true
		);

		wp_set_script_translations( 'blocksmith-editor', 'blocksmith' );
	}
);
