<?php
/**
 * The blocksmith/generate-layout ability.
 *
 * This is the core of Blocksmith: it grounds the model in the active theme's
 * design tokens and the site's registered blocks, asks for a structured JSON
 * response, then validates and normalises the result through WordPress' native
 * block parser/serialiser. Nothing is persisted — the caller (editor sidebar,
 * REST, or an MCP agent) decides what to do with the returned markup.
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'blocksmith/generate-layout',
			array(
				'label'               => __( 'Generate Layout', 'blocksmith' ),
				'description'         => __( 'Generates a complete, on-theme Gutenberg block layout from a natural-language prompt, using only the blocks registered on this site and the active theme\'s design tokens. Returns block markup ready to insert into the editor.', 'blocksmith' ),
				'category'            => BLOCKSMITH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'    => array(
							'type'        => 'string',
							'description' => 'What to build, in natural language (e.g. "a hero section with a heading, intro paragraph and a call-to-action button, followed by a three-column features list").',
							'minLength'   => 1,
						),
						'postTitle' => array(
							'type'        => 'string',
							'description' => 'Optional title of the page being authored, for additional context.',
						),
						'tone'      => array(
							'type'        => 'string',
							'description' => 'Optional writing tone.',
							'enum'        => array( 'professional', 'casual', 'creative', 'minimal', 'bold' ),
						),
						'audience'  => array(
							'type'        => 'string',
							'description' => 'Optional description of the target audience.',
						),
						'useMedia'         => array(
							'type'        => 'boolean',
							'description' => 'Whether to include real images from the media library. Default true.',
							'default'     => true,
						),
						'useInternalLinks' => array(
							'type'        => 'boolean',
							'description' => 'Whether to offer real internal links (pages/posts) for the AI to link to. Default true.',
							'default'     => true,
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blockMarkup' => array(
							'type'        => 'string',
							'description' => 'Gutenberg block markup, ready to pass to wp.blocks.parse / parse_blocks.',
						),
						'summary'     => array(
							'type'        => 'string',
							'description' => 'Short human-readable summary of what was generated.',
						),
						'warnings'    => array(
							'type'        => 'array',
							'description' => 'Names of any blocks the model used that are not registered on this site.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => 'blocksmith_ability_generate_layout',
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						// Generative action: takes input and is non-idempotent, so it is
						// exposed over REST as POST (the Abilities REST layer maps
						// readonly:true to GET-only). It still modifies nothing in WordPress.
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Execute callback for blocksmith/generate-layout.
 *
 * @param array<string, mixed> $input Validated input (prompt required).
 * @return array<string, mixed>|WP_Error Structured layout or an error.
 */
function blocksmith_ability_generate_layout( array $input = array() ) {
	$prompt = trim( (string) ( $input['prompt'] ?? '' ) );
	if ( '' === $prompt ) {
		return new WP_Error( 'blocksmith_missing_prompt', __( 'A prompt is required.', 'blocksmith' ) );
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'blocksmith_no_ai_client', __( 'The WordPress AI Client is not available.', 'blocksmith' ) );
	}

	$theme   = blocksmith_ability_get_theme_context();
	$blocks  = blocksmith_ability_list_blocks();
	$allowed = wp_list_pluck( $blocks['blocks'], 'name' );

	// Media: WP 7.0's AI client does not run the tool loop in a single call, so
	// rather than letting the model "search" mid-generation we retrieve relevant
	// images up front and inject them as a catalogue the model must pick from.
	$use_media = ! array_key_exists( 'useMedia', $input ) || (bool) $input['useMedia'];
	$media     = array();
	if ( $use_media && function_exists( 'blocksmith_ability_search_media' ) ) {
		$found = blocksmith_ability_search_media(
			array(
				'query' => $prompt,
				'limit' => 8,
			)
		);
		$media = $found['items'];
	}

	// Internal links: same retrieval-and-inject approach, so any internal links
	// the model adds point at real site content.
	$use_links = ! array_key_exists( 'useInternalLinks', $input ) || (bool) $input['useInternalLinks'];
	$links     = array();
	if ( $use_links && function_exists( 'blocksmith_ability_search_internal_links' ) ) {
		// List existing site content (no query) so the model knows what pages it
		// can actually link to, rather than searching by the prompt text.
		$found_links = blocksmith_ability_search_internal_links(
			array(
				'query' => '',
				'limit' => 15,
			)
		);
		$links = $found_links['items'];
	}

	$system = blocksmith_build_layout_system_instruction( $theme, $allowed, $input, $media, $links );
	$json_schema  = array(
		'type'                 => 'object',
		'properties'           => array(
			'blockMarkup' => array(
				'type'        => 'string',
				'description' => 'The complete Gutenberg block markup for the requested layout, using HTML block comment delimiters (e.g. <!-- wp:heading --> ... <!-- /wp:heading -->).',
			),
			'summary'     => array(
				'type'        => 'string',
				'description' => 'One or two sentences describing the generated layout.',
			),
		),
		// Strict structured-output providers (e.g. OpenAI) require every key in
		// `properties` to be listed in `required`, so both are mandatory here.
		'required'             => array( 'blockMarkup', 'summary' ),
		'additionalProperties' => false,
	);

	// The WP wrapper exposes snake_case methods and returns WP_Error (not exceptions)
	// from the generating call, so the fluent chain itself is safe to build.
	$response = wp_ai_client_prompt( $prompt )
		->using_system_instruction( $system )
		->as_json_response( $json_schema )
		->generate_text();

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( (string) $response, true );
	if ( ! is_array( $data ) || empty( $data['blockMarkup'] ) ) {
		return new WP_Error( 'blocksmith_invalid_response', __( 'The AI response did not contain usable block markup.', 'blocksmith' ) );
	}

	$markup = (string) $data['blockMarkup'];

	// Validate + normalise via WordPress' native parser/serialiser. A clean
	// round-trip both confirms the markup parses and tidies whitespace, without
	// mutating block structure (which would risk misaligning innerContent).
	$parsed = parse_blocks( $markup );
	if ( empty( array_filter( $parsed, static fn ( array $b ): bool => ! empty( $b['blockName'] ) ) ) ) {
		return new WP_Error( 'blocksmith_no_blocks', __( 'The AI response did not contain any valid blocks.', 'blocksmith' ) );
	}

	$warnings   = array();
	$registry   = WP_Block_Type_Registry::get_instance();
	blocksmith_collect_unregistered_blocks( $parsed, $registry, $warnings );

	$markup_out = serialize_blocks( $parsed );

	// Resolve any internal links the model guessed back to real catalog URLs.
	if ( $links && function_exists( 'blocksmith_repair_internal_links' ) ) {
		list( $markup_out ) = blocksmith_repair_internal_links( $markup_out, $links );
	}

	return array(
		'blockMarkup' => $markup_out,
		'summary'     => (string) ( $data['summary'] ?? '' ),
		'warnings'    => array_values( array_unique( $warnings ) ),
	);
}

/**
 * Build the system instruction grounding the model in this site's theme + blocks.
 *
 * @param array<string, mixed>             $theme   Output of blocksmith_ability_get_theme_context().
 * @param list<string>                     $allowed Registered block names.
 * @param array<string, mixed>             $input   Ability input.
 * @param array<int, array<string, mixed>> $media   Available media items to offer as a catalogue.
 * @param array<int, array<string, mixed>> $links   Available internal links to offer as a catalogue.
 * @return string
 */
function blocksmith_build_layout_system_instruction( array $theme, array $allowed, array $input, array $media = array(), array $links = array() ): string {
	$color_slugs = wp_list_pluck( $theme['colors'] ?? array(), 'slug' );
	$font_slugs  = wp_list_pluck( $theme['fontFamilies'] ?? array(), 'slug' );
	$tone        = (string) ( $input['tone'] ?? 'professional' );
	$audience    = (string) ( $input['audience'] ?? 'a general audience' );
	$title       = (string) ( $input['postTitle'] ?? '' );

	$lines = array(
		'You are an expert WordPress content designer who builds beautiful, accessible page layouts using Gutenberg blocks.',
		'',
		'Output ONLY valid Gutenberg block markup (HTML comment delimiters such as <!-- wp:heading {"level":2} --><h2>...</h2><!-- /wp:heading -->), placed in the "blockMarkup" field of your JSON response. Do not include explanations or code fences in that field.',
		'',
		'Hard rules:',
		'- Use ONLY these registered block types: ' . implode( ', ', $allowed ) . '.',
		'- Prefer core layout blocks (core/group, core/columns, core/column, core/cover, core/buttons) to create structured, responsive sections.',
		'- Establish a clear heading hierarchy (a single h1 or h2 lead, then subsections).',
		'- Never invent image URLs. Only use images from the "Available images" list below (when present); otherwise omit image blocks entirely.',
		'- Never invent internal links. For links to this site\'s own pages or posts, use ONLY URLs from the "Available internal links" list below (when present).',
	);

	if ( $color_slugs ) {
		$lines[] = '- Stay on-theme: when setting colors, use these theme color slugs (e.g. {"backgroundColor":"' . $color_slugs[0] . '"}): ' . implode( ', ', $color_slugs ) . '.';
	}
	if ( $font_slugs ) {
		$lines[] = '- Use these theme font family slugs where appropriate (e.g. {"fontFamily":"' . $font_slugs[0] . '"}): ' . implode( ', ', $font_slugs ) . '.';
	}
	if ( ! empty( $theme['layout']['contentSize'] ) ) {
		$lines[] = '- The theme content width is ' . $theme['layout']['contentSize'] . ' (wide: ' . (string) ( $theme['layout']['wideSize'] ?? '' ) . '); design within that.';
	}

	$lines[] = '';
	if ( $media ) {
		$lines[] = 'Available images. Use core/image ONLY with one of these; set the block "id" attribute and the <img> src to the EXACT id and url shown, and use the provided alt text:';
		foreach ( $media as $item ) {
			$lines[] = sprintf(
				'- id %d | %dx%d | "%s" | url: %s',
				(int) $item['id'],
				(int) $item['width'],
				(int) $item['height'],
				'' !== (string) $item['alt'] ? (string) $item['alt'] : (string) $item['title'],
				(string) $item['url']
			);
		}
	} else {
		$lines[] = 'No media library images are available. Do not include any image blocks — design with text, color, and layout only.';
	}

	if ( $links ) {
		$lines[] = '';
		$lines[] = 'Available internal links. For links to this site, use ONLY these URLs (never invent internal URLs); choose link text that fits the destination:';
		foreach ( $links as $link ) {
			$lines[] = sprintf( '- "%s" (%s): %s', (string) $link['title'], (string) $link['type'], (string) $link['url'] );
		}
	}

	$lines[] = '';
	$lines[] = 'Writing tone: ' . $tone . '. Target audience: ' . $audience . '.';
	if ( '' !== $title ) {
		$lines[] = 'The page title is: "' . $title . '".';
	}

	return implode( "\n", $lines );
}

/**
 * Recursively collect block names that are not registered on this site.
 *
 * @param array<int, array<string, mixed>> $blocks   Parsed blocks (parse_blocks output).
 * @param WP_Block_Type_Registry           $registry Block registry.
 * @param list<string>                     $warnings Accumulator (by reference).
 */
function blocksmith_collect_unregistered_blocks( array $blocks, WP_Block_Type_Registry $registry, array &$warnings ): void {
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;
		if ( is_string( $name ) && '' !== $name && ! $registry->is_registered( $name ) ) {
			$warnings[] = $name;
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			blocksmith_collect_unregistered_blocks( $block['innerBlocks'], $registry, $warnings );
		}
	}
}
