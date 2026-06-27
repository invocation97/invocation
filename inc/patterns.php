<?php
/**
 * The blocksmith/list-patterns ability + pattern context helper.
 *
 * Patterns are designed, reusable *sections* — the unit most authors actually
 * think in. Surfacing the site's registered patterns (theme + core + custom)
 * lets the AI compose from real sections and learn which (often custom) blocks
 * each section uses.
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
			'blocksmith/list-patterns',
			array(
				'label'               => __( 'List Patterns', 'blocksmith' ),
				'description'         => __( 'Lists the block patterns (reusable sections) registered on this site, with their categories and the block types they use. Optionally includes each pattern\'s block markup.', 'blocksmith' ),
				'category'            => BLOCKSMITH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'          => array(
							'type'        => 'string',
							'description' => 'Optional search term matched against pattern title and description.',
						),
						'category'       => array(
							'type'        => 'string',
							'description' => 'Optional pattern category slug to filter by.',
						),
						'includeContent' => array(
							'type'        => 'boolean',
							'description' => 'Whether to include each pattern\'s full block markup. Default false (catalog only).',
							'default'     => false,
						),
						'limit'          => array(
							'type'        => 'integer',
							'description' => 'Maximum number of patterns to return.',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 40,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'categories'  => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'blocks'      => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'content'     => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => 'blocksmith_ability_list_patterns',
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
 * Execute callback for blocksmith/list-patterns.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed> Patterns.
 */
function blocksmith_ability_list_patterns( array $input = array() ): array {
	$query           = trim( (string) ( $input['query'] ?? '' ) );
	$category        = trim( (string) ( $input['category'] ?? '' ) );
	$include_content = (bool) ( $input['includeContent'] ?? false );
	$limit           = max( 1, min( 100, (int) ( $input['limit'] ?? 40 ) ) );

	$items = blocksmith_get_patterns_for_context( 1000, $include_content );

	if ( '' !== $query ) {
		$needle = strtolower( $query );
		$items  = array_values(
			array_filter(
				$items,
				static fn ( array $p ): bool =>
					str_contains( strtolower( $p['title'] ), $needle )
					|| str_contains( strtolower( $p['description'] ), $needle )
			)
		);
	}

	if ( '' !== $category ) {
		$items = array_values(
			array_filter(
				$items,
				static fn ( array $p ): bool => in_array( $category, $p['categories'], true )
			)
		);
	}

	$total = count( $items );
	$items = array_slice( $items, 0, $limit );

	return array(
		'items' => $items,
		'total' => $total,
	);
}

/**
 * Build a compact catalogue of registered patterns for context injection.
 *
 * @param int  $limit           Maximum patterns to return.
 * @param bool $include_content Whether to include each pattern's block markup.
 * @return array<int, array<string, mixed>>
 */
function blocksmith_get_patterns_for_context( int $limit = 40, bool $include_content = false ): array {
	if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
		return array();
	}

	$registered = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
	$out        = array();

	foreach ( $registered as $pattern ) {
		// Skip patterns hidden from the inserter (utility/hidden patterns).
		if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
			continue;
		}

		$content = (string) ( $pattern['content'] ?? '' );
		$blocks  = array();
		if ( '' !== $content ) {
			$names = array();
			blocksmith_collect_block_names( parse_blocks( $content ), $names );
			$blocks = array_slice( array_values( array_unique( $names ) ), 0, 12 );
		}

		$item = array(
			'name'        => (string) ( $pattern['name'] ?? '' ),
			'title'       => (string) ( $pattern['title'] ?? '' ),
			'description' => (string) ( $pattern['description'] ?? '' ),
			'categories'  => array_values( array_map( 'strval', (array) ( $pattern['categories'] ?? array() ) ) ),
			'blocks'      => $blocks,
		);
		if ( $include_content ) {
			$item['content'] = $content;
		}

		$out[] = $item;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	return $out;
}

/**
 * Rank patterns by relevance to a query and return the top N.
 *
 * Scores by how many query terms appear in the pattern's title, categories and
 * description. With no query (or no matches) it falls back to the existing order.
 *
 * @param array<int, array<string, mixed>> $patterns Pattern catalogue.
 * @param string                           $query    Prompt/query to rank against.
 * @param int                              $limit    Maximum to return.
 * @return array<int, array<string, mixed>>
 */
function blocksmith_rank_patterns( array $patterns, string $query, int $limit ): array {
	$terms = array_values(
		array_filter(
			array_map( 'strtolower', preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY ) ?: array() ),
			static fn ( string $t ): bool => strlen( $t ) > 2
		)
	);

	if ( empty( $terms ) ) {
		return array_slice( $patterns, 0, $limit );
	}

	$scored = array();
	foreach ( $patterns as $index => $pattern ) {
		$haystack = strtolower(
			(string) ( $pattern['title'] ?? '' ) . ' '
			. implode( ' ', (array) ( $pattern['categories'] ?? array() ) ) . ' '
			. (string) ( $pattern['description'] ?? '' )
		);
		$score = 0;
		foreach ( $terms as $term ) {
			if ( str_contains( $haystack, $term ) ) {
				++$score;
			}
		}
		$scored[] = array(
			'pattern' => $pattern,
			'score'   => $score,
			'index'   => $index,
		);
	}

	// Highest score first; stable on original order for ties (PHP 8 sort is stable).
	usort( $scored, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );

	return array_map(
		static fn ( array $entry ) => $entry['pattern'],
		array_slice( $scored, 0, $limit )
	);
}

/**
 * Get a single registered pattern (with content) by its slug.
 *
 * @param string $name Pattern slug, e.g. "twentytwentyfive/cta-centered-heading".
 * @return array{name: string, title: string, content: string}|null
 */
function blocksmith_get_pattern_by_name( string $name ): ?array {
	if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
		return null;
	}
	$registry = WP_Block_Patterns_Registry::get_instance();
	if ( ! $registry->is_registered( $name ) ) {
		return null;
	}
	$pattern = $registry->get_registered( $name );

	return array(
		'name'    => $name,
		'title'   => (string) ( $pattern['title'] ?? $name ),
		'content' => (string) ( $pattern['content'] ?? '' ),
	);
}
