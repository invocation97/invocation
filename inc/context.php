<?php
/**
 * Shared generation context for Blocksmith abilities.
 *
 * This is the seam that both generate-layout and refine-block build on: it
 * gathers the grounding context (theme tokens, allowed blocks, media, internal
 * links), renders it into system-instruction lines, and finalises model output
 * (validate, normalise, repair links). New context "providers" (block subsets,
 * patterns, a site brief, …) can be added here in one place.
 *
 * @package Blocksmith
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gather the grounding context for a generation/refinement request.
 *
 * @param string               $media_query Query used to find relevant media (usually the prompt/instruction).
 * @param array<string, mixed> $input       Ability input (read for useMedia / useInternalLinks flags).
 * @return array{theme: array<string, mixed>, allowed: list<string>, media: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
 */
function blocksmith_gather_context( string $media_query, array $input ): array {
	$theme   = blocksmith_ability_get_theme_context();
	$blocks  = blocksmith_ability_list_blocks();
	$allowed = wp_list_pluck( $blocks['blocks'], 'name' );

	// WP 7.0's AI client does not run the tool loop in a single call, so instead
	// of letting the model "search" mid-generation we retrieve up front and inject
	// the results as catalogues it must pick from.
	$media = array();
	if ( ( ! array_key_exists( 'useMedia', $input ) || (bool) $input['useMedia'] ) && function_exists( 'blocksmith_ability_search_media' ) ) {
		$found = blocksmith_ability_search_media(
			array(
				'query' => $media_query,
				'limit' => 8,
			)
		);
		$media = $found['items'];
	}

	$links = array();
	if ( ( ! array_key_exists( 'useInternalLinks', $input ) || (bool) $input['useInternalLinks'] ) && function_exists( 'blocksmith_ability_search_internal_links' ) ) {
		// List existing site content (no query) so the model knows what pages it
		// can actually link to, rather than searching by the prompt text.
		$found = blocksmith_ability_search_internal_links(
			array(
				'query' => '',
				'limit' => 15,
			)
		);
		$links = $found['items'];
	}

	return array(
		'theme'   => $theme,
		'allowed' => $allowed,
		'media'   => $media,
		'links'   => $links,
	);
}

/**
 * Render the shared grounding context into system-instruction lines.
 *
 * Wording is preservation-aware so it works for both fresh generation and
 * refinement of existing markup.
 *
 * @param array<string, mixed> $ctx Output of blocksmith_gather_context().
 * @return list<string>
 */
function blocksmith_context_grounding_lines( array $ctx ): array {
	$theme       = $ctx['theme'] ?? array();
	$allowed     = $ctx['allowed'] ?? array();
	$media       = $ctx['media'] ?? array();
	$links       = $ctx['links'] ?? array();
	$color_slugs = wp_list_pluck( $theme['colors'] ?? array(), 'slug' );
	$font_slugs  = wp_list_pluck( $theme['fontFamilies'] ?? array(), 'slug' );

	$lines   = array();
	$lines[] = 'Constraints:';
	$lines[] = '- Use ONLY these registered block types: ' . implode( ', ', $allowed ) . '.';
	$lines[] = '- Never invent image URLs. Preserve any image already present; if you add an image, use ONLY one from the "Available images" list below.';
	$lines[] = '- Never invent internal links. Preserve existing links; for new links to this site use ONLY URLs from the "Available internal links" list below.';

	if ( $color_slugs ) {
		$lines[] = '- When setting colors, use theme color slugs (e.g. {"backgroundColor":"' . $color_slugs[0] . '"}): ' . implode( ', ', $color_slugs ) . '.';
	}
	if ( $font_slugs ) {
		$lines[] = '- When setting fonts, use theme font family slugs (e.g. {"fontFamily":"' . $font_slugs[0] . '"}): ' . implode( ', ', $font_slugs ) . '.';
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
		$lines[] = 'No media library images are available. Do not add image blocks.';
	}

	if ( $links ) {
		$lines[] = '';
		$lines[] = 'Available internal links. For links to this site, use ONLY these URLs; choose link text that fits the destination:';
		foreach ( $links as $link ) {
			$lines[] = sprintf( '- "%s" (%s): %s', (string) $link['title'], (string) $link['type'], (string) $link['url'] );
		}
	}

	return $lines;
}

/**
 * Validate, normalise and repair model-produced block markup.
 *
 * Parses the markup, ensures it contains real blocks, collects any unregistered
 * block names as warnings, round-trips through serialize_blocks(), and repairs
 * internal links against the context's link catalogue.
 *
 * @param string               $markup Raw block markup from the model.
 * @param array<string, mixed> $ctx    Output of blocksmith_gather_context().
 * @return array{blockMarkup: string, warnings: list<string>}|WP_Error
 */
function blocksmith_finalize_markup( string $markup, array $ctx ) {
	$parsed = parse_blocks( $markup );
	if ( empty( array_filter( $parsed, static fn ( array $b ): bool => ! empty( $b['blockName'] ) ) ) ) {
		return new WP_Error( 'blocksmith_no_blocks', __( 'The AI response did not contain any valid blocks.', 'blocksmith' ) );
	}

	$warnings = array();
	blocksmith_collect_unregistered_blocks( $parsed, WP_Block_Type_Registry::get_instance(), $warnings );

	$out = serialize_blocks( $parsed );

	if ( ! empty( $ctx['links'] ) && function_exists( 'blocksmith_repair_internal_links' ) ) {
		list( $out ) = blocksmith_repair_internal_links( $out, $ctx['links'] );
	}

	return array(
		'blockMarkup' => $out,
		'warnings'    => array_values( array_unique( $warnings ) ),
	);
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
