<?php
/**
 * The blocksmith/search-media ability.
 *
 * Lets the AI find real images that already exist in the site's media library,
 * so generated layouts reference actual attachments instead of hallucinated
 * URLs. Exposed over REST + MCP and intended to be offered to generate-layout
 * as a callable tool.
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
			'blocksmith/search-media',
			array(
				'label'               => __( 'Search Media', 'blocksmith' ),
				'description'         => __( 'Searches the WordPress media library for attachments matching a query (by title, caption, alt text, or filename). Returns real attachment IDs and URLs so layouts can use existing media instead of inventing image URLs.', 'blocksmith' ),
				'category'            => BLOCKSMITH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Search term. Leave empty to return the most recent media.',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Maximum number of items to return.',
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 10,
						),
						'mimeType' => array(
							'type'        => 'string',
							'description' => 'MIME type filter (e.g. "image", "image/png", "video"). Defaults to images.',
							'default'     => 'image',
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
									'id'       => array( 'type' => 'integer' ),
									'url'      => array( 'type' => 'string' ),
									'title'    => array( 'type' => 'string' ),
									'alt'      => array( 'type' => 'string' ),
									'width'    => array( 'type' => 'integer' ),
									'height'   => array( 'type' => 'integer' ),
									'mimeType' => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => 'blocksmith_ability_search_media',
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
 * Execute callback for blocksmith/search-media.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed> Matching media items.
 */
function blocksmith_ability_search_media( array $input = array() ): array {
	$query = trim( (string) ( $input['query'] ?? '' ) );
	$limit = (int) ( $input['limit'] ?? 10 );
	$limit = max( 1, min( 50, $limit ) );
	$mime  = trim( (string) ( $input['mimeType'] ?? 'image' ) );

	global $wpdb;

	// MIME condition: a bare type like "image" matches "image/%"; a full type
	// like "image/png" matches exactly.
	$mime_sql = '';
	if ( '' !== $mime ) {
		if ( str_contains( $mime, '/' ) ) {
			$mime_sql = $wpdb->prepare( ' AND p.post_mime_type = %s', $mime );
		} else {
			$mime_sql = $wpdb->prepare( ' AND p.post_mime_type LIKE %s', $wpdb->esc_like( $mime ) . '/%' );
		}
	}

	// Search across title, caption (excerpt), description (content), alt text
	// (postmeta) and filename. Terms are OR-matched to favour recall, which is
	// what an AI image search wants.
	$search_sql = '';
	$terms      = '' !== $query ? preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY ) : array();
	if ( $terms ) {
		$clauses = array();
		foreach ( $terms as $term ) {
			$like      = '%' . $wpdb->esc_like( $term ) . '%';
			$clauses[] = $wpdb->prepare(
				'(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR alt.meta_value LIKE %s OR file.meta_value LIKE %s)',
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}
		$search_sql = ' AND (' . implode( ' OR ', $clauses ) . ')';
	}

	$join  = " LEFT JOIN {$wpdb->postmeta} alt ON ( alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt' )";
	$join .= " LEFT JOIN {$wpdb->postmeta} file ON ( file.post_id = p.ID AND file.meta_key = '_wp_attached_file' )";
	$where = "WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'" . $mime_sql . $search_sql;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clauses are individually prepared above.
	$total = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p{$join} {$where}" );
	$ids   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p{$join} {$where} ORDER BY p.post_date DESC LIMIT %d",
			$limit
		)
	);
	// phpcs:enable

	$items = array();
	foreach ( $ids as $raw_id ) {
		$id   = (int) $raw_id;
		$src  = wp_get_attachment_image_src( $id, 'full' );
		$meta = wp_get_attachment_metadata( $id );

		$items[] = array(
			'id'       => $id,
			'url'      => $src ? (string) $src[0] : (string) wp_get_attachment_url( $id ),
			'title'    => (string) get_the_title( $id ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'width'    => $src ? (int) $src[1] : (int) ( $meta['width'] ?? 0 ),
			'height'   => $src ? (int) $src[2] : (int) ( $meta['height'] ?? 0 ),
			'mimeType' => (string) get_post_mime_type( $id ),
		);
	}

	return array(
		'items' => $items,
		'total' => $total,
	);
}
