<?php
/**
 * Expose Invocation's abilities over MCP using the official WordPress MCP
 * Adapter (https://github.com/WordPress/mcp-adapter).
 *
 * Rather than hand-roll the protocol, we register a custom MCP server with the
 * adapter and let it handle transport, spec versioning, auth and validation.
 * MCP is an optional enhancement: if the adapter isn't installed, the rest of
 * Invocation still works and we show an admin notice.
 *
 * Endpoint (HTTP transport): /wp-json/invocation/mcp
 *
 * @package Invocation
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
function invocation_mcp_abilities(): array {
	return array(
		'invocation/generate-layout',
		'invocation/refine-block',
		'invocation/list-patterns',
		'invocation/search-media',
		'invocation/search-internal-links',
		'invocation/get-theme-context',
		'invocation/list-blocks',
		'invocation/gather-site-context',
		'invocation/create-page',
		'invocation/update-page',
	);
}

/**
 * Register the Invocation MCP server with the adapter.
 */
add_action(
	'mcp_adapter_init',
	static function ( $adapter ): void {
		$adapter->create_server(
			'invocation',
			'invocation',
			'mcp',
			'Invocation',
			__( 'Build on-theme Gutenberg layouts, fill section patterns, refine blocks, and read theme, media and link context.', 'invocation' ),
			'v' . INVOCATION_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			invocation_mcp_abilities(),
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
		// Keep the notice scoped to the plugin's own page (Guideline 11).
		$screen = get_current_screen();
		if ( ! $screen || ! defined( 'INVOCATION_ADMIN_SLUG' ) || 'toplevel_page_' . INVOCATION_ADMIN_SLUG !== $screen->id ) {
			return;
		}
		$link    ='<a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noreferrer noopener">' . esc_html__( 'MCP Adapter', 'invocation' ) . '</a>';
		$message = sprintf(
			/* translators: %s: link to the MCP Adapter plugin. */
			esc_html__( 'Invocation: install the %s plugin to use Invocation from Claude Code and other AI agents over MCP. The editor features work without it.', 'invocation' ),
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
