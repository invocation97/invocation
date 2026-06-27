<?php
/**
 * Expose Blocksmith's abilities over MCP using the official WordPress MCP
 * Adapter (https://github.com/WordPress/mcp-adapter).
 *
 * Rather than hand-roll the protocol, we register a custom MCP server with the
 * adapter and let it handle transport, spec versioning, auth and validation.
 * MCP is an optional enhancement: if the adapter isn't installed, the rest of
 * Blocksmith still works and we show an admin notice.
 *
 * Endpoint (HTTP transport): /wp-json/blocksmith/mcp
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities exposed as MCP tools.
 *
 * @return list<string>
 */
function blocksmith_mcp_abilities(): array {
	return array(
		'blocksmith/generate-layout',
		'blocksmith/refine-block',
		'blocksmith/list-patterns',
		'blocksmith/search-media',
		'blocksmith/search-internal-links',
		'blocksmith/get-theme-context',
		'blocksmith/list-blocks',
		'blocksmith/gather-site-context',
	);
}

/**
 * Register the Blocksmith MCP server with the adapter.
 */
add_action(
	'mcp_adapter_init',
	static function ( $adapter ): void {
		$adapter->create_server(
			'blocksmith',
			'blocksmith',
			'mcp',
			'Blocksmith',
			__( 'Build on-theme Gutenberg layouts, fill section patterns, refine blocks, and read theme, media and link context.', 'blocksmith' ),
			'v' . BLOCKSMITH_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			blocksmith_mcp_abilities(),
			array(),
			array()
		);
	}
);

/**
 * Nudge admins to install the MCP Adapter so the MCP tools become available.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) || class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			return;
		}
		$link    = '<a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noreferrer noopener">' . esc_html__( 'MCP Adapter', 'blocksmith' ) . '</a>';
		$message = sprintf(
			/* translators: %s: link to the MCP Adapter plugin. */
			esc_html__( 'Blocksmith: install the %s plugin to use Blocksmith from Claude Code and other AI agents over MCP. The editor features work without it.', 'blocksmith' ),
			$link
		);
		wp_admin_notice(
			$message,
			array(
				'type'               => 'info',
				'additional_classes' => array( 'is-dismissible' ),
			)
		);
	}
);
