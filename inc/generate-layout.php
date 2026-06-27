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

	$ctx         = blocksmith_gather_context( $prompt, $input );
	$system      = blocksmith_build_layout_system_instruction( $ctx, $input );
	$json_schema = array(
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

	$final = blocksmith_finalize_markup( (string) $data['blockMarkup'], $ctx );
	if ( is_wp_error( $final ) ) {
		return $final;
	}

	return array(
		'blockMarkup' => $final['blockMarkup'],
		'summary'     => (string) ( $data['summary'] ?? '' ),
		'warnings'    => $final['warnings'],
	);
}

/**
 * Build the system instruction for generating a layout.
 *
 * Task-specific framing on top of the shared grounding context.
 *
 * @param array<string, mixed> $ctx   Output of blocksmith_gather_context().
 * @param array<string, mixed> $input Ability input.
 * @return string
 */
function blocksmith_build_layout_system_instruction( array $ctx, array $input ): string {
	$tone     = (string) ( $input['tone'] ?? 'professional' );
	$audience = (string) ( $input['audience'] ?? 'a general audience' );
	$title    = (string) ( $input['postTitle'] ?? '' );

	$lines = array(
		'You are an expert WordPress content designer who builds beautiful, accessible page layouts using Gutenberg blocks.',
		'',
		'Output ONLY valid Gutenberg block markup (HTML comment delimiters such as <!-- wp:heading {"level":2} --><h2>...</h2><!-- /wp:heading -->), placed in the "blockMarkup" field of your JSON response. Do not include explanations or code fences in that field.',
		'',
		'Layout guidance:',
		'- Prefer core layout blocks (core/group, core/columns, core/column, core/cover, core/buttons) to create structured, responsive sections.',
		'- Establish a clear heading hierarchy (a single h1 or h2 lead, then subsections).',
		'',
	);

	$lines = array_merge( $lines, blocksmith_context_grounding_lines( $ctx ) );

	$lines[] = '';
	$lines[] = 'Writing tone: ' . $tone . '. Target audience: ' . $audience . '.';
	if ( '' !== $title ) {
		$lines[] = 'The page title is: "' . $title . '".';
	}

	return implode( "\n", $lines );
}
