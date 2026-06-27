<?php
/**
 * The blocksmith/refine-block ability.
 *
 * Takes existing Gutenberg block markup plus a natural-language instruction and
 * returns a revised version — same theme grounding and validation as
 * generate-layout, but scoped to refining what the user already has selected in
 * the editor.
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
			'blocksmith/refine-block',
			array(
				'label'               => __( 'Refine Block', 'blocksmith' ),
				'description'         => __( 'Refines existing Gutenberg block markup according to a natural-language instruction, staying on-theme and using only registered blocks. Returns the revised block markup.', 'blocksmith' ),
				'category'            => BLOCKSMITH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'blockMarkup' => array(
							'type'        => 'string',
							'description' => 'The current Gutenberg block markup to refine.',
							'minLength'   => 1,
						),
						'instruction' => array(
							'type'        => 'string',
							'description' => 'How the block(s) should be changed (e.g. "make the heading punchier and shorten the paragraph").',
							'minLength'   => 1,
						),
						'tone'        => array(
							'type'        => 'string',
							'description' => 'Optional writing tone.',
							'enum'        => array( 'professional', 'casual', 'creative', 'minimal', 'bold' ),
						),
					),
					'required'             => array( 'blockMarkup', 'instruction' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blockMarkup' => array(
							'type'        => 'string',
							'description' => 'The revised Gutenberg block markup.',
						),
						'summary'     => array(
							'type'        => 'string',
							'description' => 'Short summary of what changed.',
						),
						'warnings'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => 'blocksmith_ability_refine_block',
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
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
 * Execute callback for blocksmith/refine-block.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed>|WP_Error Revised markup or an error.
 */
function blocksmith_ability_refine_block( array $input = array() ) {
	$markup      = trim( (string) ( $input['blockMarkup'] ?? '' ) );
	$instruction = trim( (string) ( $input['instruction'] ?? '' ) );

	if ( '' === $markup ) {
		return new WP_Error( 'blocksmith_missing_markup', __( 'Block markup is required.', 'blocksmith' ) );
	}
	if ( '' === $instruction ) {
		return new WP_Error( 'blocksmith_missing_instruction', __( 'An instruction is required.', 'blocksmith' ) );
	}
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'blocksmith_no_ai_client', __( 'The WordPress AI Client is not available.', 'blocksmith' ) );
	}

	$theme   = blocksmith_ability_get_theme_context();
	$blocks  = blocksmith_ability_list_blocks();
	$allowed = wp_list_pluck( $blocks['blocks'], 'name' );

	$system      = blocksmith_build_refine_system_instruction( $theme, $allowed, $input );
	$user_prompt = "Instruction:\n" . $instruction
		. "\n\nRefine the following Gutenberg block markup accordingly and return the COMPLETE revised markup. Preserve any existing image URLs and ids exactly.\n\n---\n"
		. $markup
		. "\n---";

	$json_schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'blockMarkup' => array(
				'type'        => 'string',
				'description' => 'The complete revised Gutenberg block markup, using HTML block comment delimiters.',
			),
			'summary'     => array(
				'type'        => 'string',
				'description' => 'One sentence describing what changed.',
			),
		),
		'required'             => array( 'blockMarkup', 'summary' ),
		'additionalProperties' => false,
	);

	$response = wp_ai_client_prompt( $user_prompt )
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

	$parsed = parse_blocks( (string) $data['blockMarkup'] );
	if ( empty( array_filter( $parsed, static fn ( array $b ): bool => ! empty( $b['blockName'] ) ) ) ) {
		return new WP_Error( 'blocksmith_no_blocks', __( 'The refined response did not contain any valid blocks.', 'blocksmith' ) );
	}

	$warnings = array();
	blocksmith_collect_unregistered_blocks( $parsed, WP_Block_Type_Registry::get_instance(), $warnings );

	return array(
		'blockMarkup' => serialize_blocks( $parsed ),
		'summary'     => (string) ( $data['summary'] ?? '' ),
		'warnings'    => array_values( array_unique( $warnings ) ),
	);
}

/**
 * Build the system instruction for refining blocks.
 *
 * @param array<string, mixed> $theme   Output of blocksmith_ability_get_theme_context().
 * @param list<string>         $allowed Registered block names.
 * @param array<string, mixed> $input   Ability input.
 * @return string
 */
function blocksmith_build_refine_system_instruction( array $theme, array $allowed, array $input ): string {
	$color_slugs = wp_list_pluck( $theme['colors'] ?? array(), 'slug' );
	$font_slugs  = wp_list_pluck( $theme['fontFamilies'] ?? array(), 'slug' );
	$tone        = (string) ( $input['tone'] ?? 'professional' );

	$lines = array(
		'You are an expert WordPress content designer who refines existing Gutenberg block markup.',
		'',
		'Output ONLY valid Gutenberg block markup (HTML comment delimiters) in the "blockMarkup" field of your JSON response — no explanations or code fences in that field.',
		'',
		'Rules:',
		'- Apply the user\'s instruction precisely; do not make unrelated changes.',
		'- Preserve the overall block structure and types unless the instruction requires otherwise.',
		'- Use ONLY these registered block types: ' . implode( ', ', $allowed ) . '.',
		'- Never invent image URLs; keep any existing core/image ids and URLs exactly as provided.',
	);

	if ( $color_slugs ) {
		$lines[] = '- When setting colors, use theme color slugs: ' . implode( ', ', $color_slugs ) . '.';
	}
	if ( $font_slugs ) {
		$lines[] = '- When setting fonts, use theme font family slugs: ' . implode( ', ', $font_slugs ) . '.';
	}

	$lines[] = '';
	$lines[] = 'Writing tone: ' . $tone . '.';

	return implode( "\n", $lines );
}
