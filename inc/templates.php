<?php
/**
 * Page-template discovery + assignment helpers.
 *
 * Block themes expose "custom templates" (e.g. Twenty Twenty-Five's "Page No
 * Title", good for marketing pages) via theme.json `customTemplates` and/or
 * classic template-header files. A page picks one through the editor's Template
 * panel, which stores the template slug in the `_wp_page_template` post meta.
 *
 * These helpers let an agent over MCP discover the assignable templates and set
 * the right one when creating/updating a page, so generated marketing pages get
 * the title-less (or otherwise appropriate) template instead of the default.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'invocation/list-templates',
			array(
				'label'               => __( 'List Templates', 'invocation' ),
				'description'         => __( 'Lists the page templates the active theme makes assignable to a given post type (e.g. "Page No Title"). Use a returned slug as the "template" when creating or updating a page.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'postType' => array(
							'type'        => 'string',
							'description' => 'Post type to list assignable templates for. Defaults to "page".',
							'default'     => 'page',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'postType' => array( 'type' => 'string' ),
						'items'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'  => array( 'type' => 'string' ),
									'title' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => 'invocation_ability_list_templates',
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}
);

/**
 * Execute callback for invocation/list-templates.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>
 */
function invocation_ability_list_templates( array $input = array() ): array {
	$post_type = (string) ( $input['postType'] ?? 'page' );
	if ( ! post_type_exists( $post_type ) ) {
		$post_type = 'page';
	}

	$items = array();
	foreach ( invocation_get_assignable_templates( $post_type ) as $slug => $title ) {
		$items[] = array(
			'slug'  => (string) $slug,
			'title' => (string) $title,
		);
	}

	return array(
		'postType' => $post_type,
		'items'    => $items,
	);
}

/**
 * The page templates the active theme makes assignable to a post type.
 *
 * Wraps WP_Theme::get_page_templates(), which merges classic template-header
 * files with block-theme theme.json `customTemplates` scoped to the post type.
 *
 * @param string $post_type Post type slug.
 * @return array<string, string> Map of template slug => human title.
 */
function invocation_get_assignable_templates( string $post_type ): array {
	$theme     = wp_get_theme();
	$templates = $theme->get_page_templates( null, $post_type );

	return is_array( $templates ) ? $templates : array();
}

/**
 * Validate a requested template slug for a post type.
 *
 * @param string $slug      Requested template slug ('' or 'default' clears it).
 * @param string $post_type Post type the template will be assigned to.
 * @return string|WP_Error Normalised slug ('' means the default template), or an error.
 */
function invocation_resolve_template( string $slug, string $post_type ) {
	$slug = trim( $slug );
	if ( '' === $slug || 'default' === $slug ) {
		return '';
	}

	$templates = invocation_get_assignable_templates( $post_type );
	if ( ! isset( $templates[ $slug ] ) ) {
		return new WP_Error(
			'invocation_invalid_template',
			sprintf(
				/* translators: 1: requested template slug, 2: comma-separated list of valid slugs. */
				__( 'Unknown template "%1$s". Assignable templates for this post type: %2$s.', 'invocation' ),
				$slug,
				$templates ? implode( ', ', array_keys( $templates ) ) : __( '(none)', 'invocation' )
			)
		);
	}

	return $slug;
}

/**
 * Assign (or clear) a page's template.
 *
 * @param int    $id   Post id.
 * @param string $slug Validated template slug; '' clears back to the default.
 */
function invocation_apply_template( int $id, string $slug ): void {
	if ( '' === $slug ) {
		delete_post_meta( $id, '_wp_page_template' );
		return;
	}
	update_post_meta( $id, '_wp_page_template', $slug );
}
