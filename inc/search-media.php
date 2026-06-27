<?php
/**
 * The invocation/search-media ability.
 *
 * Lets the AI find real images in the media library so generated layouts
 * reference actual attachments instead of invented URLs.
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
			'invocation/search-media',
			array(
				'label'               => __( 'Search Media', 'invocation' ),
				'description'         => __( 'Searches the WordPress media library for attachments matching a query (by title, caption, alt text, or filename). Returns real attachment IDs and URLs so layouts can use existing media instead of inventing image URLs.', 'invocation' ),
				'category'            => INVOCATION_ABILITY_CATEGORY,
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
				'execute_callback'    => 'invocation_ability_search_media',
				'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
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
 * Execute callback for invocation/search-media.
 *
 * @param array<string, mixed> $input Validated input.
 * @return array<string, mixed> Matching media items.
 */
function invocation_ability_search_media( array $input = array() ): array {
	$query = trim( (string) ( $input['query'] ?? '' ) );
	$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
	$mime  = trim( (string) ( $input['mimeType'] ?? 'image' ) );

	$cache_key = 'search_media_' . md5( $query . '|' . $mime . '|' . $limit );
	$cached    = wp_cache_get( $cache_key, 'invocation' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	// post_type/post_status are bound (not interpolated) so the whole query is
	// driven through prepare().
	$where = 'p.post_type = %s AND p.post_status = %s';
	$args  = array( 'attachment', 'inherit' );

	if ( '' !== $mime ) {
		// A bare type like "image" matches "image/%"; a full type matches exactly.
		if ( str_contains( $mime, '/' ) ) {
			$where .= ' AND p.post_mime_type = %s';
			$args[] = $mime;
		} else {
			$where .= ' AND p.post_mime_type LIKE %s';
			$args[] = $wpdb->esc_like( $mime ) . '/%';
		}
	}

	// Search title, caption, description, alt text and filename; OR-match terms
	// to favour recall, which is what an AI image search wants.
	$terms = '' !== $query ? preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY ) : array();
	if ( $terms ) {
		$clauses = array();
		foreach ( $terms as $term ) {
			$like      = '%' . $wpdb->esc_like( $term ) . '%';
			$clauses[] = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR alt.meta_value LIKE %s OR file.meta_value LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$where .= ' AND (' . implode( ' OR ', $clauses ) . ')';
	}

	$join = " LEFT JOIN {$wpdb->postmeta} alt ON ( alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt' )"
		. " LEFT JOIN {$wpdb->postmeta} file ON ( file.post_id = p.ID AND file.meta_key = '_wp_attached_file' )";

	$count_sql  = "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p{$join} WHERE {$where}";
	$select_sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p{$join} WHERE {$where} ORDER BY p.post_date DESC LIMIT %d";

	// The SQL is assembled dynamically (a variable number of search clauses), but
	// every user value is bound through prepare() and table names come from $wpdb.
	// A direct query is required — WP_Query has no equivalent for OR-matching across
	// title, alt text and filename — and the result is cached above.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
	$ids   = $wpdb->get_col( $wpdb->prepare( $select_sql, array_merge( $args, array( $limit ) ) ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

	$result = array(
		'items' => $items,
		'total' => $total,
	);
	wp_cache_set( $cache_key, $result, 'invocation', MINUTE_IN_SECONDS );

	return $result;
}
